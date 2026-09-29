<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use App\Services\NumberSequenceService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Numara NumberSequenceService ile üretilir. Durum durumları (partial, completed, cancelled) yalnızca test
 * kurgusu içindir; uygulamada durum OrderService üzerinden değişir.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => fn () => app(NumberSequenceService::class)->next('order'),
            'customer_id' => Customer::factory(),
            'order_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
            'status' => OrderStatus::Open,
        ];
    }

    /** Kalem ekler (her çağrı yeni bir motor modeliyle). */
    public function withItem(int $quantity = 1, ?MotorModel $model = null): static
    {
        return $this->afterCreating(fn (Order $order) => $order->items()->create([
            'motor_model_id' => ($model ?? MotorModel::factory()->create())->getKey(),
            'quantity' => $quantity,
        ]));
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Open]);
    }

    public function partial(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Partial]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Cancelled, 'cancellation_reason' => 'Müşteri vazgeçti']);
    }
}
