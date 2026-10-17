<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\Currency;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\MotorUnitStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Faturalar (CLAUDE.md bölüm 5 "Faturalar"): taslak → kesildi → iptal.
 */
class InvoiceService
{
    public const DEFAULT_VAT_RATE = '20';

    public function __construct(
        private readonly NumberSequenceService $numbers,
        private readonly ExchangeRateService $rates,
        private readonly PriceService $prices,
        private readonly StockService $stock,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * Taslak fatura açar.
     *
     * @param  array{type: InvoiceType|string, customer_id: int, invoice_date?: mixed, due_date?: mixed, currency?: mixed, exchange_rate?: mixed, counterparty_number?: ?string, original_invoice_id?: ?int, notes?: ?string}  $data
     */
    public function createDraft(array $data): Invoice
    {
        $type = $data['type'] instanceof InvoiceType ? $data['type'] : InvoiceType::from((string) $data['type']);

        return DB::transaction(function () use ($type, $data): Invoice {
            $invoice = new Invoice;
            $invoice->forceFill([
                'direction' => $type->direction(),
                'kind' => $type->kind(),
                'status' => InvoiceStatus::Draft,
            ]);

            $this->fillHeader($invoice, $data, creating: true);
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function updateDraft(Invoice $invoice, array $data, int $expectedLockVersion): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $expectedLockVersion): Invoice {
            $this->ensureDraft($invoice);
            $this->fillHeader($invoice, $data, creating: false);
            $invoice->saveExpectingVersion($expectedLockVersion);

            return $invoice;
        });
    }

    /**
     * Satış faturasına sevk edilmiş motorlardan satır ekler (model başına). Birim fiyat verilmezse etkin fiyat
     * (fatura para birimindeyse) kullanılır.
     *
     * @param  iterable<MotorUnit|int>  $units
     */
    public function addMotorLine(Invoice $invoice, iterable $units, mixed $unitPrice = null, mixed $discountRate = 0, mixed $vatRate = self::DEFAULT_VAT_RATE): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $units, $unitPrice, $discountRate, $vatRate): InvoiceLine {
            $invoice = $this->lockDraft($invoice);

            if ($invoice->type() !== InvoiceType::SalesInvoice) {
                throw new BusinessRuleException('Motor satırı yalnızca satış faturasına eklenir.');
            }

            $ids = collect($units)->map(fn ($u) => $u instanceof MotorUnit ? $u->getKey() : (int) $u)->unique()->values();
            $motors = MotorUnit::query()->with(['motorModel', 'order'])->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($motors->isEmpty() || $motors->count() !== $ids->count()) {
                throw new BusinessRuleException('Faturalanacak motor seçilmedi veya bulunamadı.');
            }

            if ($motors->pluck('motor_model_id')->unique()->count() > 1) {
                throw new BusinessRuleException('Bir satırdaki motorlar aynı modelde olmalıdır.');
            }

            foreach ($motors as $motor) {
                $this->ensureInvoiceableMotor($motor, $invoice);
            }

            /** @var MotorModel $model */
            $model = $motors->first()->motorModel;

            if ($unitPrice === null || $unitPrice === '') {
                $price = $this->prices->priceFor($model, $invoice->customer_id, $invoice->invoice_date);

                if ($price === null || $price->currency !== $invoice->currency) {
                    throw new BusinessRuleException("{$model->code} için {$invoice->currency->value} cinsinden geçerli fiyat yok; birim fiyatı girin.");
                }

                $unitPrice = (string) $price->unit_price;
            }

            $line = new InvoiceLine;
            $line->forceFill([
                'invoice_id' => $invoice->getKey(),
                'motor_model_id' => $model->getKey(),
                'description' => "{$model->code} {$model->name}",
                'quantity' => (string) $motors->count(),
                'unit' => 'Adet',
            ]);
            $this->fillAmounts($line, $unitPrice, $discountRate, $vatRate);
            $line->save();
            $line->motorUnits()->attach($motors->pluck('id'));

            $this->recalculate($invoice);

            return $line;
        });
    }

    /**
     * Elle satır (satış: nakliye, hizmet; iadeler) veya alış faturasında parça satırı. Alış faturası satırı
     * aynı parçanın stok girişlerine bağlanabilir ($stockMovementIds).
     *
     * @param  array{part_id?: ?int, description?: ?string, quantity: mixed, unit?: ?string, unit_price: mixed, discount_rate?: mixed, vat_rate?: mixed, stock_movement_ids?: list<int>}  $data
     */
    public function addLine(Invoice $invoice, array $data): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $data): InvoiceLine {
            $invoice = $this->lockDraft($invoice);

            $line = new InvoiceLine;
            $line->invoice_id = $invoice->getKey();
            $this->fillLine($invoice, $line, $data);
            $line->save();
            $this->syncStockLinks($invoice, $line, $data['stock_movement_ids'] ?? []);

            $this->recalculate($invoice);

            return $line;
        });
    }

    /** Taslak satırını günceller. Motor satırında miktar seri numaralarından gelir; fiyat, iskonto ve KDV değişir. */
    public function updateLine(InvoiceLine $line, array $data): InvoiceLine
    {
        return DB::transaction(function () use ($line, $data): InvoiceLine {
            $invoice = $this->lockDraft($line->invoice);

            if ($line->motor_model_id !== null) {
                $this->fillAmounts($line, $data['unit_price'] ?? $line->unit_price, $data['discount_rate'] ?? $line->discount_rate, $data['vat_rate'] ?? $line->vat_rate);
            } else {
                $this->fillLine($invoice, $line, $data);
            }

            $line->save();

            if (array_key_exists('stock_movement_ids', $data)) {
                $this->syncStockLinks($invoice, $line, $data['stock_movement_ids'] ?? []);
            }

            $this->recalculate($invoice);

            return $line;
        });
    }

    public function removeLine(InvoiceLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $invoice = $this->lockDraft($line->invoice);

            $line->motorUnits()->detach();
            $line->stockMovements()->detach();
            $line->delete();

            $this->recalculate($invoice);
        });
    }

    /** Taslağı siler (numarası yoktur). */
    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = $this->lockDraft($invoice);

            foreach ($invoice->lines as $line) {
                $line->motorUnits()->detach();
                $line->stockMovements()->detach();
                $line->delete();
            }

            $invoice->delete();
        });
    }

    /**
     * Faturayı keser: kontroller, alış satırları için stok girişi (bağlı giriş yoksa), toplamlar, TL karşılığı,
     * bizim kestiğimiz belgede FTR numarası. Kesildikten sonra fatura kilitlidir.
     */
    public function issue(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor): Invoice {
            $invoice = $this->lockDraft($invoice);
            $type = $invoice->type();
            $lines = $invoice->lines()->with(['part.unit', 'motorUnits.order', 'stockMovements'])->orderBy('id')->get();

            if ($lines->isEmpty()) {
                throw new BusinessRuleException('Faturada en az bir satır olmalıdır.');
            }

            $this->ensureCustomer($invoice->customer()->firstOrFail(), $type);

            if (! $type->issuedByUs() && blank($invoice->counterparty_number)) {
                throw new BusinessRuleException("{$type->label()} karşı tarafın kestiği belgedir; belge numarasını girin.");
            }

            foreach ($lines as $line) {
                foreach ($line->motorUnits as $motor) {
                    $this->ensureInvoiceableMotor($motor, $invoice, $line);
                }

                if ($type === InvoiceType::PurchaseInvoice) {
                    $this->receivePurchaseLine($invoice, $line, $actor);
                }
            }

            $this->recalculate($invoice);

            if ($type->issuedByUs()) {
                $invoice->invoice_number = $this->numbers->next('invoice', $invoice->invoice_date);
            }

            $invoice->grand_total_try = Money::mul((string) $invoice->grand_total, (string) $invoice->exchange_rate);
            $invoice->status = InvoiceStatus::Issued;
            $invoice->issued_at = now();
            $invoice->issued_by_user_id = $actor->getKey();
            $invoice->save();

            // Cari hareketi: satış faturası ve alış iadesi cariyi borçlandırır, alış faturası ve satış iadesi alacaklandırır.
            if (Money::compare((string) $invoice->grand_total, '0') !== 0) {
                $this->ledger->post(
                    $invoice->customer_id,
                    $invoice->currency,
                    AccountTransactionType::Invoice,
                    $this->receivableSign($type) > 0 ? (string) $invoice->grand_total : bcmul((string) $invoice->grand_total, '-1', 2),
                    $invoice->invoice_date,
                    $invoice,
                    $actor,
                    "{$type->label()} {$invoice->displayNumber()}",
                    dueDate: $invoice->due_date,
                );
            }

            return $invoice;
        });
    }

    /**
     * Kesilmiş faturayı iptal eder (açıklama zorunlu). Alış faturasında kesimde oluşan stok girişleri ters
     * kaydedilir; stok yetmezse iptal reddedilir. Motorlar ve önceden var olan stok girişleri serbest kalır.
     */
    public function cancel(Invoice $invoice, User $actor, ?string $reason): Invoice
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessRuleException('İptal açıklaması zorunludur.');
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($locked->status !== InvoiceStatus::Issued) {
                throw new BusinessRuleException('Yalnızca kesilmiş fatura iptal edilebilir.');
            }

            $created = StockMovement::query()
                ->where('reference_type', $locked->getMorphClass())
                ->where('reference_id', $locked->getKey())
                ->where('type', StockMovementType::StockIn->value)
                ->whereDoesntHave('correction')
                ->orderBy('id')
                ->get();

            foreach ($created as $movement) {
                try {
                    $this->stock->correct($movement, $actor, "Fatura iptali ({$locked->displayNumber()}): {$reason}");
                } catch (InsufficientStockException $exception) {
                    throw new BusinessRuleException("Faturayla girilen malzeme kullanılmış; iptal stoğu eksiye düşürür.\n".implode("\n", array_slice(explode("\n", $exception->getMessage()), 1)));
                }
            }

            $this->ledger->reverseFor($locked, $actor, "Fatura iptali ({$locked->displayNumber()}): {$reason}");

            $locked->status = InvoiceStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            $invoice->setRawAttributes($locked->getAttributes(), sync: true);

            return $invoice;
        });
    }

    /**
     * Satış faturasına eklenebilecek motorlar: sevk edilmiş, iptal edilmemiş bir faturada olmayan, siparişi varsa
     * siparişin müşterisi fatura carisi olan motorlar.
     *
     * @return Collection<int, MotorUnit>
     */
    public function invoiceableMotors(Invoice $invoice, ?int $motorModelId = null): Collection
    {
        return MotorUnit::query()
            ->with('motorModel')
            ->where('status', MotorUnitStatus::Shipped->value)
            ->when($motorModelId, fn ($q) => $q->where('motor_model_id', $motorModelId))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereHas('order', fn ($o) => $o->where('customer_id', $invoice->customer_id)))
            ->whereDoesntHave('invoiceLines', fn ($l) => $l->whereHas('invoice', fn ($i) => $i->where('status', '!=', InvoiceStatus::Cancelled->value)))
            ->orderBy('serial_number')
            ->get();
    }

    /**
     * Alış satırına bağlanabilecek stok girişleri: aynı parçanın, ters kaydedilmemiş, iptal edilmemiş bir
     * faturaya bağlı olmayan giriş hareketleri.
     *
     * @return Collection<int, StockMovement>
     */
    public function linkableReceipts(int $partId, ?InvoiceLine $except = null): Collection
    {
        return StockMovement::query()
            ->where('part_id', $partId)
            ->where('type', StockMovementType::StockIn->value)
            ->whereDoesntHave('correction')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('invoice_line_stock_movements as l')
                ->join('invoice_lines as il', 'il.id', '=', 'l.invoice_line_id')
                ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
                ->whereColumn('l.stock_movement_id', 'stock_movements.id')
                ->where('i.status', '!=', InvoiceStatus::Cancelled->value)
                ->when($except, fn ($q) => $q->where('l.invoice_line_id', '!=', $except->getKey())))
            ->orderByDesc('id')
            ->get();
    }

    /** Belge cariyi borçlandırıyor mu (+1) yoksa alacaklandırıyor mu (−1)? */
    public function receivableSign(InvoiceType $type): int
    {
        return in_array($type, [InvoiceType::SalesInvoice, InvoiceType::PurchaseReturn], true) ? 1 : -1;
    }

    private function receivePurchaseLine(Invoice $invoice, InvoiceLine $line, User $actor): void
    {
        $linked = $line->stockMovements;

        if ($linked->isEmpty()) {
            $movement = $this->stock->receiveForInvoice($line->part, (string) $line->quantity, $invoice, $actor, "Alış faturası {$invoice->counterparty_number}");
            $line->stockMovements()->attach($movement->getKey());

            return;
        }

        $sum = $linked->reduce(fn (string $carry, StockMovement $m) => Quantity::add($carry, (string) $m->quantity), '0');

        if (Quantity::compare($sum, (string) $line->quantity) !== 0) {
            throw new BusinessRuleException(sprintf(
                '%s satırı %s, bağlı stok girişleri toplamı %s. Miktarlar eşit olmalı; gerekirse önce stok girişini düzeltin.',
                $line->description,
                Quantity::format((string) $line->quantity, $line->unit),
                Quantity::format($sum, $line->unit),
            ));
        }
    }

    private function fillHeader(Invoice $invoice, array $data, bool $creating): void
    {
        if ($creating || array_key_exists('customer_id', $data)) {
            $customer = Customer::query()->find($data['customer_id'] ?? 0) ?? throw new BusinessRuleException('Cari hesap seçilmedi.');
            $this->ensureCustomer($customer, $invoice->type());
            $invoice->customer_id = $customer->getKey();
        } else {
            $customer = $invoice->customer()->firstOrFail();
        }

        if ($creating || array_key_exists('invoice_date', $data)) {
            $invoice->invoice_date = CarbonImmutable::parse(filled($data['invoice_date'] ?? null) ? $data['invoice_date'] : today())->startOfDay();
        }

        if (filled($data['due_date'] ?? null)) {
            $invoice->due_date = CarbonImmutable::parse($data['due_date'])->startOfDay();
        } elseif ($creating || $invoice->isDirty('invoice_date')) {
            $invoice->due_date = CarbonImmutable::parse($invoice->invoice_date)->addDays($customer->payment_term_days);
        }

        if ($invoice->due_date->lt($invoice->invoice_date)) {
            throw new BusinessRuleException('Vade fatura tarihinden önce olamaz.');
        }

        if ($creating || array_key_exists('currency', $data)) {
            $invoice->currency = filled($data['currency'] ?? null)
                ? ($data['currency'] instanceof Currency ? $data['currency'] : Currency::from((string) $data['currency']))
                : $customer->default_currency;
        }

        if (filled($data['exchange_rate'] ?? null) && $invoice->currency !== Currency::TRY) {
            $rate = Money::parse($data['exchange_rate'], Money::RATE_SCALE);

            if (Money::compare($rate, '0') <= 0) {
                throw new BusinessRuleException('Kur sıfırdan büyük olmalıdır.');
            }

            $invoice->exchange_rate = $rate;
        } elseif ($creating || $invoice->isDirty(['currency', 'invoice_date'])) {
            $invoice->exchange_rate = $this->rates->rateFor($invoice->currency, $invoice->invoice_date);
        }

        if (array_key_exists('counterparty_number', $data)) {
            $number = filled($data['counterparty_number']) ? trim($data['counterparty_number']) : null;

            if ($number !== null && $invoice->type()->issuedByUs()) {
                throw new BusinessRuleException('Bizim kestiğimiz belgeye karşı taraf numarası girilmez; numara kesilince verilir.');
            }

            $duplicate = $number !== null && Invoice::query()
                ->where('customer_id', $invoice->customer_id)
                ->where('counterparty_number', $number)
                ->when($invoice->exists, fn ($q) => $q->whereKeyNot($invoice->getKey()))
                ->exists();

            if ($duplicate) {
                throw new BusinessRuleException("Bu caride {$number} numaralı belge zaten kayıtlı.");
            }

            $invoice->counterparty_number = $number;
        }

        if (array_key_exists('original_invoice_id', $data)) {
            $invoice->original_invoice_id = filled($data['original_invoice_id']) ? $this->originalFor($invoice, (int) $data['original_invoice_id']) : null;
        }

        if (array_key_exists('notes', $data)) {
            $invoice->notes = filled($data['notes']) ? trim($data['notes']) : null;
        }
    }

    /** İadenin bağlandığı orijinal fatura: aynı cari, aynı yön, normal ve kesilmiş. */
    private function originalFor(Invoice $invoice, int $originalId): int
    {
        if ($invoice->kind !== InvoiceKind::Return) {
            throw new BusinessRuleException('Orijinal fatura yalnızca iade belgesine bağlanır.');
        }

        $original = Invoice::query()->find($originalId);

        if ($original === null || $original->customer_id !== $invoice->customer_id || $original->direction !== $invoice->direction
            || $original->kind !== InvoiceKind::Normal || $original->status !== InvoiceStatus::Issued) {
            throw new BusinessRuleException('İade, aynı carinin kesilmiş aynı yöndeki faturasına bağlanabilir.');
        }

        return $original->getKey();
    }

    private function fillLine(Invoice $invoice, InvoiceLine $line, array $data): void
    {
        $type = $invoice->type();

        if ($type === InvoiceType::PurchaseInvoice) {
            $part = Part::query()->with('unit')->find($data['part_id'] ?? $line->part_id) ?? throw new BusinessRuleException('Alış faturası satırında parça seçilmelidir.');

            if (! $part->type->isManuallyAssignable()) {
                throw new BusinessRuleException('Mamul kartı alış faturasına yazılamaz.');
            }

            $line->part_id = $part->getKey();
            $line->description = filled($data['description'] ?? null) ? trim($data['description']) : "{$part->code} {$part->name}";
            $line->unit = $part->unit->name;
            $quantity = Quantity::parse($data['quantity'] ?? $line->quantity ?? '');

            if (! $part->unit->allows_decimal && ! Quantity::isWhole($quantity)) {
                throw new BusinessRuleException("{$part->code} için miktar tam sayı olmalıdır (birim: {$part->unit->name}).");
            }
        } else {
            $line->part_id = null;
            $line->description = trim((string) ($data['description'] ?? $line->description));
            $line->unit = filled($data['unit'] ?? null) ? trim($data['unit']) : ($line->unit ?? 'Adet');
            $quantity = Quantity::parse($data['quantity'] ?? $line->quantity ?? '');

            if ($line->description === '') {
                throw new BusinessRuleException('Satır açıklaması zorunludur.');
            }
        }

        if (Quantity::compare($quantity, '0') <= 0) {
            throw new BusinessRuleException('Miktar sıfırdan büyük olmalıdır.');
        }

        $line->quantity = $quantity;
        $this->fillAmounts($line, $data['unit_price'] ?? $line->unit_price, $data['discount_rate'] ?? $line->discount_rate ?? 0, $data['vat_rate'] ?? $line->vat_rate ?? self::DEFAULT_VAT_RATE);
    }

    /** Satır tutarları: brüt, iskonto, net, KDV, toplam (kuruşa yarım yukarı yuvarlanır). */
    private function fillAmounts(InvoiceLine $line, mixed $unitPrice, mixed $discountRate, mixed $vatRate): void
    {
        $price = Money::parse($unitPrice ?? '', 4);
        $discount = $this->rate($discountRate, 'İskonto');
        $vat = $this->rate($vatRate, 'KDV');

        if (Money::compare($price, '0') < 0) {
            throw new BusinessRuleException('Birim fiyat negatif olamaz.');
        }

        $gross = Money::mul((string) $line->quantity, $price);
        $discountAmount = Money::percent($gross, $discount);
        $net = Money::sub($gross, $discountAmount);
        $vatAmount = Money::percent($net, $vat);

        $line->forceFill([
            'unit_price' => $price,
            'discount_rate' => $discount,
            'vat_rate' => $vat,
            'line_gross' => $gross,
            'discount_amount' => $discountAmount,
            'line_net' => $net,
            'vat_amount' => $vatAmount,
            'line_total' => Money::add($net, $vatAmount),
        ]);
    }

    private function rate(mixed $value, string $label): string
    {
        $rate = Money::parse($value ?? 0);

        if (Money::compare($rate, '0') < 0 || Money::compare($rate, '100') > 0) {
            throw new BusinessRuleException("{$label} oranı 0 ile 100 arasında olmalıdır.");
        }

        return $rate;
    }

    /** Fatura toplamları satırların toplamıdır. */
    private function recalculate(Invoice $invoice): void
    {
        $totals = InvoiceLine::query()->where('invoice_id', $invoice->getKey())
            ->selectRaw('COALESCE(SUM(line_gross), 0) AS gross, COALESCE(SUM(discount_amount), 0) AS discount, COALESCE(SUM(vat_amount), 0) AS vat, COALESCE(SUM(line_total), 0) AS total')
            ->first();

        $invoice->forceFill([
            'subtotal' => (string) $totals->gross,
            'discount_total' => (string) $totals->discount,
            'vat_total' => (string) $totals->vat,
            'grand_total' => (string) $totals->total,
        ])->save();
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncStockLinks(Invoice $invoice, InvoiceLine $line, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids !== [] && $invoice->type() !== InvoiceType::PurchaseInvoice) {
            throw new BusinessRuleException('Stok girişi yalnızca alış faturası satırına bağlanır.');
        }

        $allowed = $this->linkableReceipts($line->part_id ?? 0, $line)->pluck('id')->all();

        if (array_diff($ids, $allowed) !== []) {
            throw new BusinessRuleException('Seçilen stok girişi bu parçaya ait değil, ters kaydedilmiş veya başka bir faturaya bağlı.');
        }

        $sum = StockMovement::query()->whereKey($ids)->sum('quantity');

        if ($ids !== [] && Quantity::compare((string) $sum, (string) $line->quantity) > 0) {
            throw new BusinessRuleException('Bağlanan stok girişlerinin toplamı satır miktarını aşamaz.');
        }

        $line->stockMovements()->sync($ids);
    }

    private function ensureInvoiceableMotor(MotorUnit $motor, Invoice $invoice, ?InvoiceLine $line = null): void
    {
        if ($motor->status !== MotorUnitStatus::Shipped) {
            throw new BusinessRuleException("{$motor->serial_number} sevk edilmemiş; yalnızca sevk edilen motor faturalanır.");
        }

        if ($motor->order !== null && $motor->order->customer_id !== $invoice->customer_id) {
            throw new BusinessRuleException("{$motor->serial_number} başka bir müşterinin siparişine ait.");
        }

        $taken = $motor->invoiceLines()
            ->when($line, fn ($q) => $q->whereKeyNot($line->getKey()))
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled->value))
            ->exists();

        if ($taken) {
            throw new BusinessRuleException("{$motor->serial_number} başka bir faturada zaten var.");
        }
    }

    private function ensureCustomer(Customer $customer, InvoiceType $type): void
    {
        if (! $customer->is_active) {
            throw new BusinessRuleException('Pasif cariye fatura kesilemez.');
        }

        if ($type->requiresCustomer() && ! $customer->is_customer) {
            throw new BusinessRuleException("{$type->label()} yalnızca müşteri işaretli cariye kesilir.");
        }

        if (! $type->requiresCustomer() && ! $customer->is_supplier) {
            throw new BusinessRuleException("{$type->label()} yalnızca tedarikçi işaretli cariye kesilir.");
        }
    }

    private function ensureDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new BusinessRuleException('Kesilmiş fatura değiştirilemez; düzeltme iade faturasıyla yapılır.');
        }
    }

    private function lockDraft(Invoice $invoice): Invoice
    {
        $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
        $this->ensureDraft($locked);

        return $locked;
    }
}
