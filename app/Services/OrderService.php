<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sipariş ve kalemleri. Kurallar (CLAUDE.md bölüm 5):
 * - Sipariş no otomatik (NumberSequenceService), en az bir kalem ve termin zorunlu, termin >= sipariş tarihi.
 * - Pasif müşteriye yeni sipariş açılamaz; pasif motor modeli yeni kaleme eklenemez.
 * - Müşteri ve kalemler yalnızca sipariş açıkken değişir; kısmi siparişte yalnızca termin, müşteri
 *   sipariş no ve notlar; tamamlanan ve iptal edilen sipariş düzenlenemez.
 * - İptal yalnızca açık ve kısmi siparişte, açıklamayla; geri alınamaz.
 */
class OrderService
{
    /** Kısmi siparişte değişebilecek alanlar. */
    private const HEADER_FIELDS = ['customer_reference', 'due_date', 'notes'];

    public function __construct(private readonly NumberSequenceService $numbers) {}

    /**
     * @param  array{customer_id: int, order_date?: mixed, due_date: mixed, customer_reference?: ?string, notes?: ?string, items: list<array{motor_model_id: int, quantity: int|string}>}  $data
     */
    public function create(array $data): Order
    {
        $data = $this->normalize($data);
        $items = $this->normalizeItems($data['items'] ?? []);

        return DB::transaction(function () use ($data, $items): Order {
            $order = new Order(Arr::only($data, ['customer_reference', 'customer_id', 'order_date', 'due_date', 'notes']));
            $order->order_date ??= today();
            $this->ensureValidDates($order);
            $this->ensureActiveCustomer($order->customer_id);

            $order->order_number = $this->numbers->next('order');
            $order->status = OrderStatus::Open;
            $order->save();

            $this->syncItems($order, $items);

            return $order;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version. Kalem değişikliği de
     *                                    siparişin sürümünü artırır.
     */
    public function update(Order $order, array $data, int $expectedLockVersion): Order
    {
        $data = $this->normalize($data);
        $items = array_key_exists('items', $data) ? $this->normalizeItems($data['items']) : null;

        return DB::transaction(function () use ($order, $data, $items, $expectedLockVersion): Order {
            // Durum okunduğu andaki hâliyle kontrol edilir; arada değiştiyse sürüm kontrolü çakışmayı yakalar.
            if (! $order->status->isEditable()) {
                throw new BusinessRuleException('Tamamlanan veya iptal edilen sipariş düzenlenemez.');
            }

            if ($order->status->allowsItemChanges()) {
                $order->fill(Arr::only($data, ['customer_id', 'order_date', ...self::HEADER_FIELDS]));
            } else {
                $order->fill(Arr::only($data, self::HEADER_FIELDS));

                if ($this->changesLockedFields($order, $data, $items)) {
                    throw new BusinessRuleException('Kısmi siparişte yalnızca termin, müşteri sipariş no ve notlar değiştirilebilir.');
                }
            }

            $this->ensureValidDates($order);

            if ($order->isDirty('customer_id')) {
                $this->ensureActiveCustomer($order->customer_id);
            }

            // Çakışma varsa burada StaleModelException fırlar ve transaction geri alınır.
            $order->saveExpectingVersion($expectedLockVersion);

            if ($items !== null && $order->status->allowsItemChanges()) {
                $this->syncItems($order, $items);
            }

            return $order;
        });
    }

    public function cancel(Order $order, ?string $reason): Order
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessRuleException('İptal açıklaması zorunludur.');
        }

        return DB::transaction(function () use ($order, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $locked->status->ensureCanTransitionTo(OrderStatus::Cancelled);

            $locked->status = OrderStatus::Cancelled;
            $locked->cancellation_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Formdaki kalemleri siparişe uygular. Kalemler motor modeline göre eşleşir (siparişte her model tek
     * kalemdir); böylece değişmeyen kalemin kimliği korunur (Aşama 4'te iş emirleri kaleme bağlanır).
     *
     * @param  array<int, int>  $items  motor_model_id => quantity
     */
    private function syncItems(Order $order, array $items): void
    {
        $existing = $order->items()->get()->keyBy('motor_model_id');

        $newModelIds = array_diff(array_keys($items), $existing->keys()->all());

        if ($newModelIds !== []) {
            $inactive = MotorModel::query()->whereKey($newModelIds)->where('is_active', false)->pluck('code');

            if ($inactive->isNotEmpty()) {
                throw new BusinessRuleException('Pasif motor modeli siparişe eklenemez: '.$inactive->implode(', '));
            }

            if (MotorModel::query()->whereKey($newModelIds)->count() !== count($newModelIds)) {
                throw new BusinessRuleException('Seçilen motor modeli bulunamadı.');
            }
        }

        foreach ($existing as $modelId => $item) {
            if (! array_key_exists($modelId, $items)) {
                $item->delete();
            } elseif ($item->quantity !== $items[$modelId]) {
                $item->quantity = $items[$modelId];
                $item->save();
            }
        }

        foreach ($newModelIds as $modelId) {
            $order->items()->create(['motor_model_id' => $modelId, 'quantity' => $items[$modelId]]);
        }

        $order->unsetRelation('items');
    }

    /**
     * @return array<int, int> motor_model_id => quantity
     */
    private function normalizeItems(mixed $items): array
    {
        $normalized = [];

        foreach (array_values((array) $items) as $item) {
            $modelId = filter_var($item['motor_model_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var(is_string($item['quantity'] ?? null) ? trim($item['quantity']) : ($item['quantity'] ?? null), FILTER_VALIDATE_INT);

            if ($modelId === false) {
                throw new BusinessRuleException('Her kalem için motor modeli seçilmelidir.');
            }

            if ($quantity === false || $quantity <= 0) {
                throw new BusinessRuleException('Kalem miktarı pozitif bir tam sayı olmalıdır.');
            }

            if (array_key_exists($modelId, $normalized)) {
                throw new BusinessRuleException('Aynı motor modeli siparişte birden fazla kalem olarak girilemez.');
            }

            $normalized[$modelId] = $quantity;
        }

        if ($normalized === []) {
            throw new BusinessRuleException('Siparişte en az bir kalem olmalıdır.');
        }

        return $normalized;
    }

    private function normalize(array $data): array
    {
        foreach (['customer_reference', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? trim($data[$field]) : null;
            }
        }

        return $data;
    }

    private function ensureValidDates(Order $order): void
    {
        if ($order->due_date === null) {
            throw new BusinessRuleException('Termin tarihi zorunludur.');
        }

        if (Carbon::parse($order->due_date)->lt(Carbon::parse($order->order_date))) {
            throw new BusinessRuleException('Termin tarihi sipariş tarihinden önce olamaz.');
        }
    }

    /**
     * Müşteri satırı paylaşımlı kilitlenir: kontrol ile sipariş kaydı arasında müşteri pasifleştirilemez.
     */
    private function ensureActiveCustomer(?int $customerId): void
    {
        $customer = Customer::query()->whereKey($customerId)->sharedLock()->first();

        if ($customer === null) {
            throw new BusinessRuleException('Müşteri bulunamadı.');
        }

        if (! $customer->is_active) {
            throw new BusinessRuleException('Pasif müşteriye sipariş açılamaz.');
        }
    }

    /** Kısmi siparişte müşteri, sipariş tarihi veya kalemler değiştirilmek isteniyor mu? */
    private function changesLockedFields(Order $order, array $data, ?array $items): bool
    {
        if (array_key_exists('customer_id', $data) && (int) $data['customer_id'] !== $order->customer_id) {
            return true;
        }

        if (array_key_exists('order_date', $data) && ! Carbon::parse($data['order_date'])->isSameDay($order->order_date)) {
            return true;
        }

        if ($items !== null) {
            $current = $order->items()->pluck('quantity', 'motor_model_id')->all();
            ksort($current);
            ksort($items);

            return $current !== $items;
        }

        return false;
    }
}
