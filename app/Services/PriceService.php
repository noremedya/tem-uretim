<?php

namespace App\Services;

use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Price;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Satış fiyatları (CLAUDE.md bölüm 5 "Cari, döviz ve fiyat").
 * - Liste fiyatı (müşterisiz) ve müşteriye özel fiyat; para birimi ve geçerlilik başlangıcıyla.
 * - Geçerliliği başlamış fiyat değiştirilemez ve silinemez; yeni fiyat kaydı açılır. Geçmiş tarihli fiyat girilemez.
 */
class PriceService
{
    /**
     * @param  array{motor_model_id: int, customer_id?: ?int, currency: Currency|string, unit_price: mixed, valid_from: mixed}  $data
     */
    public function create(array $data): Price
    {
        return DB::transaction(function () use ($data): Price {
            $price = new Price;
            $price->forceFill([
                'motor_model_id' => (int) ($data['motor_model_id'] ?? 0),
                'customer_id' => filled($data['customer_id'] ?? null) ? (int) $data['customer_id'] : null,
            ]);
            $this->fill($price, $data);
            $this->ensureValid($price);
            $price->save();

            return $price;
        });
    }

    /**
     * Yalnızca ileri tarihli fiyat düzenlenir (para birimi, tutar, geçerlilik başlangıcı).
     *
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Price $price, array $data, int $expectedLockVersion): Price
    {
        return DB::transaction(function () use ($price, $data, $expectedLockVersion): Price {
            $this->ensureFuture($price);
            $this->fill($price, $data);
            $this->ensureValid($price);
            $price->saveExpectingVersion($expectedLockVersion);

            return $price;
        });
    }

    /** Yalnızca ileri tarihli fiyat silinir. */
    public function delete(Price $price): void
    {
        DB::transaction(function () use ($price): void {
            $this->ensureFuture(Price::query()->lockForUpdate()->findOrFail($price->getKey()));
            $price->delete();
        });
    }

    /**
     * Etkin fiyat: tarihe kadar başlamış en son kayıt; önce müşteriye özel, yoksa liste fiyatı.
     */
    public function priceFor(MotorModel|int $model, Customer|int|null $customer, ?CarbonInterface $date = null): ?Price
    {
        $modelId = $model instanceof MotorModel ? $model->getKey() : $model;
        $customerId = $customer instanceof Customer ? $customer->getKey() : $customer;
        $date ??= today();

        $latest = fn (?int $customerId) => Price::query()
            ->where('motor_model_id', $modelId)
            ->when($customerId === null, fn ($q) => $q->whereNull('customer_id'), fn ($q) => $q->where('customer_id', $customerId))
            ->whereDate('valid_from', '<=', $date)
            ->orderByDesc('valid_from')
            ->first();

        return ($customerId !== null ? $latest($customerId) : null) ?? $latest(null);
    }

    private function fill(Price $price, array $data): void
    {
        if (array_key_exists('currency', $data)) {
            $price->currency = $data['currency'] instanceof Currency
                ? $data['currency']
                : (Currency::tryFrom((string) $data['currency']) ?? throw new BusinessRuleException('Geçersiz para birimi.'));
        }

        if (array_key_exists('unit_price', $data)) {
            $amount = Money::parse($data['unit_price'] ?? '');

            if (Money::compare($amount, '0') <= 0) {
                throw new BusinessRuleException('Fiyat sıfırdan büyük olmalıdır.');
            }

            $price->unit_price = $amount;
        }

        if (array_key_exists('valid_from', $data)) {
            $price->valid_from = filled($data['valid_from']) ? CarbonImmutable::parse($data['valid_from'])->startOfDay() : null;
        }
    }

    private function ensureValid(Price $price): void
    {
        if ($price->currency === null || $price->unit_price === null) {
            throw new BusinessRuleException('Para birimi ve fiyat zorunludur.');
        }

        if ($price->valid_from === null) {
            throw new BusinessRuleException('Geçerlilik başlangıcı zorunludur.');
        }

        if ($price->valid_from->lt(today())) {
            throw new BusinessRuleException('Geçmiş tarihli fiyat girilemez; geçerlilik bugün veya sonrası olmalıdır.');
        }

        $model = MotorModel::query()->find($price->motor_model_id);

        if ($model === null || ! $model->is_active) {
            throw new BusinessRuleException('Fiyat yalnızca aktif motor modeline girilir.');
        }

        if ($price->customer_id !== null) {
            $customer = Customer::query()->find($price->customer_id);

            if ($customer === null || ! $customer->is_active || ! $customer->is_customer) {
                throw new BusinessRuleException('Müşteri fiyatı yalnızca aktif ve müşteri işaretli cariye girilir.');
            }
        }

        $duplicate = Price::query()
            ->where('motor_model_id', $price->motor_model_id)
            ->when($price->customer_id === null, fn ($q) => $q->whereNull('customer_id'), fn ($q) => $q->where('customer_id', $price->customer_id))
            ->whereDate('valid_from', $price->valid_from)
            ->when($price->exists, fn ($q) => $q->whereKeyNot($price->getKey()))
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleException("Bu model için {$price->valid_from->format('d.m.Y')} tarihinden geçerli bir fiyat zaten var.");
        }
    }

    private function ensureFuture(Price $price): void
    {
        if (! $price->isFuture()) {
            throw new BusinessRuleException('Geçerliliği başlamış fiyat değiştirilemez veya silinemez; yeni bir fiyat kaydı açın.');
        }
    }
}
