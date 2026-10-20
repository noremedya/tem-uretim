<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * Rapor filtreleri. Kuyruktaki dışa aktarım için diziye çevrilip geri okunabilir.
 */
final readonly class ReportFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $grouping = 'day',
        public ?int $motorModelId = null,
        public ?int $operatorId = null,
        public ?int $batchId = null,
        public ?string $partType = null,
        public ?int $partId = null,
        public ?int $warehouseId = null,
        public ?int $customerId = null,
        public ?string $orderStatus = null,
        public bool $includeSemiFinished = false,
    ) {}

    public static function fromArray(array $data): self
    {
        [$defaultFrom, $defaultTo] = ReportPeriod::range($data['preset'] ?? 'this_month');
        $int = fn (string $key): ?int => filled($data[$key] ?? null) ? (int) $data[$key] : null;
        $enum = fn (string $key): ?string => filled($data[$key] ?? null) ? (is_object($data[$key]) ? $data[$key]->value : (string) $data[$key]) : null;

        $from = filled($data['from'] ?? null) ? CarbonImmutable::parse($data['from'])->startOfDay() : $defaultFrom;
        $to = filled($data['to'] ?? null) ? CarbonImmutable::parse($data['to'])->startOfDay() : $defaultTo;

        return new self(
            from: $from->min($to),
            to: $to->max($from),
            grouping: in_array($data['grouping'] ?? null, ['day', 'week', 'month'], true) ? $data['grouping'] : 'day',
            motorModelId: $int('motor_model_id'),
            operatorId: $int('operator_id'),
            batchId: $int('batch_id'),
            partType: $enum('part_type'),
            partId: $int('part_id'),
            warehouseId: $int('warehouse_id'),
            customerId: $int('customer_id'),
            orderStatus: $enum('order_status'),
            includeSemiFinished: (bool) ($data['include_semi_finished'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'preset' => 'custom',
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'grouping' => $this->grouping,
            'motor_model_id' => $this->motorModelId,
            'operator_id' => $this->operatorId,
            'batch_id' => $this->batchId,
            'part_type' => $this->partType,
            'part_id' => $this->partId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'order_status' => $this->orderStatus,
            'include_semi_finished' => $this->includeSemiFinished,
        ];
    }

    /** Bitiş gününden sonraki günün başlangıcı (zaman damgalı alanlar için üst sınır, hariç). */
    public function toExclusive(): CarbonImmutable
    {
        return $this->to->addDay();
    }

    public function rangeLabel(): string
    {
        return $this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y');
    }
}
