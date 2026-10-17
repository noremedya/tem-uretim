<?php

namespace App\Reports;

use App\Enums\Permission;
use App\Models\User;

/**
 * Rapor tanımı: ekran tablosu ve Excel aynı tanımdan üretilir (CLAUDE.md bölüm 8).
 */
abstract class Report
{
    /** Kalıcı anahtar (dışa aktarım kaydında saklanır). */
    abstract public static function key(): string;

    abstract public function title(): string;

    abstract public function permission(): Permission;

    /** @return list<ReportColumn> */
    abstract public function columns(): array;

    /** @return list<array<string, mixed>> */
    abstract public function rows(ReportFilters $filters): array;

    /**
     * Raporun kullandığı filtreler (ekranda gösterilecek alanlar): grouping, motor_model_id, operator_id, batch_id,
     * part_type, part_id, customer_id, order_status, include_semi_finished.
     *
     * @return list<string>
     */
    abstract public function filters(): array;

    /**
     * Özet satırları (etiket => biçimlenmiş değer). Ekranda tablonun üstünde, Excel'de "Özet" sayfasında.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function summary(ReportFilters $filters, array $rows): array
    {
        return ['Kayıt sayısı' => (string) count($rows)];
    }

    public function canView(?User $user): bool
    {
        return $user?->can($this->permission()) ?? false;
    }

    public function fileName(ReportFilters $filters): string
    {
        return sprintf('%s_%s_%s.xlsx', static::key(), $filters->from->format('Ymd'), $filters->to->format('Ymd'));
    }
}
