<?php

namespace App\Services;

use App\Models\MotorUnit;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hızlı İşlem kod çözümü (CLAUDE.md bölüm 7). Sıra: parça barkodu veya kodu → seri no → iş emri no.
 * Büyük/küçük harf ve baştaki/sondaki boşluk önemsizdir.
 */
class QuickScanService
{
    public const NOT_FOUND = 'Kod bulunamadı.';

    public const NOT_ASSIGNED = 'Bu iş emri size atanmamış.';

    /**
     * @return array{type: 'part'|'motor'|'work_order'|'not_found'|'not_assigned', record: ?Model, message: ?string}
     */
    public function resolve(?string $code, User $user): array
    {
        $code = trim((string) $code);

        if ($code === '') {
            return $this->result('not_found', null, self::NOT_FOUND);
        }

        $lower = mb_strtolower($code);

        $part = Part::query()->with(['unit', 'stockBalance'])
            ->where(fn ($q) => $q->whereRaw('lower(barcode) = ?', [$lower])->orWhereRaw('lower(code) = ?', [$lower]))
            // Barkod eşleşmesi kod eşleşmesinden önce gelir.
            ->orderByRaw('lower(barcode) = ? DESC', [$lower])
            ->first();

        if ($part !== null && $user->can('view', $part)) {
            return $this->result('part', $part);
        }

        $motor = MotorUnit::query()->with(['motorModel', 'workOrder', 'order.customer'])->whereRaw('lower(serial_number) = ?', [$lower])->first();

        if ($motor !== null && $user->can('view', $motor)) {
            return $this->result('motor', $motor);
        }

        $workOrder = WorkOrder::query()->with(['product.unit', 'assignedUser'])->whereRaw('lower(work_order_number) = ?', [$lower])->first();

        if ($workOrder !== null) {
            if ($user->can('view', $workOrder)) {
                return $this->result('work_order', $workOrder);
            }

            // İş emri görebilen ama bu iş emrine atanmamış operatör: bilgi gösterilmez.
            if ($user->can('viewAny', WorkOrder::class)) {
                return $this->result('not_assigned', null, self::NOT_ASSIGNED);
            }
        }

        return $this->result('not_found', null, self::NOT_FOUND);
    }

    /** @return array{type: string, record: ?Model, message: ?string} */
    private function result(string $type, ?Model $record, ?string $message = null): array
    {
        return ['type' => $type, 'record' => $record, 'message' => $message];
    }
}
