<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\WorkOrderStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * İş emri: bir yarı mamul veya mamul kartı üretir. Yalnızca WorkOrderService değiştirir.
 */
class WorkOrder extends Model
{
    use HasOptimisticLocking, LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'quantity' => 'decimal:3',
            'produced_quantity' => 'decimal:3',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'product_part_id');
    }

    /** Malzemenin çekildiği depo (boşsa her malzeme kendi deposundan).
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /** Üretilen ürünün girdiği depo.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    /** @return BelongsTo<MotorModel, $this> */
    public function motorModel(): BelongsTo
    {
        return $this->belongsTo(MotorModel::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<ProductionBatch, $this> */
    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return HasMany<WorkOrderMaterial, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    /** Eş ürün çıktıları (ana ürünle birlikte stoğa girer).
     *
     * @return HasMany<WorkOrderOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(WorkOrderOutput::class);
    }

    /** @return HasMany<WorkOrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(WorkOrderStatusHistory::class);
    }

    /** @return HasMany<MotorUnit, $this> */
    public function motorUnits(): HasMany
    {
        return $this->hasMany(MotorUnit::class);
    }

    /** @return HasMany<WorkOrderProduction, $this> */
    public function productions(): HasMany
    {
        return $this->hasMany(WorkOrderProduction::class);
    }

    /** Henüz üretilmemiş adet (kısım kısım üretim). */
    public function remainingQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->produced_quantity, 3);
    }

    public function hasProduction(): bool
    {
        return bccomp((string) $this->produced_quantity, '0', 3) > 0;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_user_id !== null && $this->assigned_user_id === $user->getKey();
    }

    /** Planlanan bitişi geçmiş ve hâlâ açık mı? */
    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->planned_end->lt(today());
    }

    /** Kullanıcının görebildiği iş emirleri: tümü (yönetici, depo) veya yalnızca kendine atananlar (operatör). */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permission::WorkOrdersViewAll)) {
            return;
        }

        $query->where('assigned_user_id', $user->getKey());
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [WorkOrderStatus::Planned->value, WorkOrderStatus::InProgress->value]);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('planned_end', '<', today());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'work_order_number', 'product_part_id', 'motor_model_id', 'order_item_id', 'quantity', 'produced_quantity',
                'production_batch_id', 'status', 'assigned_user_id', 'planned_start', 'planned_end',
                'started_at', 'completed_at', 'cancellation_reason', 'notes', 'source_warehouse_id', 'target_warehouse_id',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
