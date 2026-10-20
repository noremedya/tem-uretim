<?php

namespace App\Support;

use App\Enums;
use App\Models;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * Denetim izi kayıtlarını Türkçe gösterir: kayıt türü, kayıt adı, işlem, alan adları ve değerler
 * (enum etiketi, bağlı kaydın adı, tarih, evet/hayır).
 */
final class AuditPresenter
{
    public const SUBJECT_LABELS = [
        'user' => 'Kullanıcı', 'unit' => 'Birim', 'brand' => 'Marka', 'part' => 'Ürün', 'motor_model' => 'Motor modeli',
        'bom_item' => 'Reçete satırı', 'customer' => 'Cari hesap', 'order' => 'Sipariş', 'order_item' => 'Sipariş kalemi',
        'production_batch' => 'Üretim partisi', 'work_order' => 'İş emri', 'motor_unit' => 'Motor', 'exchange_rate' => 'Döviz kuru',
        'price' => 'Fiyat', 'invoice' => 'Fatura', 'invoice_line' => 'Fatura satırı', 'cash_account' => 'Kasa/banka hesabı',
        'payment' => 'Tahsilat/ödeme', 'cheque' => 'Çek/senet',
    ];

    /** @var array<class-string, array<int|string, Model|null>> */
    private static array $related = [];

    public const EVENT_LABELS = [
        'created' => 'Oluşturuldu', 'updated' => 'Güncellendi', 'deleted' => 'Silindi',
        'login' => 'Giriş', 'logout' => 'Çıkış', 'failed' => 'Başarısız giriş',
    ];

    private const ATTRIBUTE_LABELS = [
        'username' => 'Kullanıcı adı', 'name' => 'Ad', 'email' => 'E-posta', 'is_active' => 'Aktif', 'roles' => 'Roller',
        'code' => 'Kod', 'unit_id' => 'Birim', 'type' => 'Tür', 'barcode' => 'Barkod', 'critical_level' => 'Kritik seviye',
        'allows_decimal' => 'Küsurata izin', 'power_kw' => 'Güç (kW)', 'speed_rpm' => 'Devir', 'voltage' => 'Gerilim',
        'frequency_hz' => 'Frekans', 'pole_count' => 'Kutup sayısı', 'frame_size' => 'Gövde ölçüsü', 'phase' => 'Faz',
        'mounting_type' => 'Montaj şekli', 'protection_class' => 'Koruma sınıfı', 'efficiency_class' => 'Verim sınıfı',
        'description' => 'Açıklama', 'parent_part_id' => 'Üst kart', 'component_part_id' => 'Bileşen',
        'quantity_per_unit' => 'Birim başına miktar', 'scrap_rate' => 'Fire oranı (%)', 'tax_number' => 'Vergi no',
        'tax_office' => 'Vergi dairesi', 'phone' => 'Telefon', 'address' => 'Adres', 'is_customer' => 'Müşteri',
        'is_supplier' => 'Tedarikçi', 'payment_term_days' => 'Vade (gün)', 'default_currency' => 'Para birimi',
        'order_number' => 'Sipariş no', 'customer_reference' => 'Müşteri sipariş no', 'customer_id' => 'Cari',
        'order_date' => 'Sipariş tarihi', 'due_date' => 'Vade/termin', 'status' => 'Durum', 'cancellation_reason' => 'İptal açıklaması',
        'notes' => 'Notlar', 'order_id' => 'Sipariş', 'motor_model_id' => 'Motor modeli', 'quantity' => 'Miktar',
        'batch_number' => 'Parti no', 'date' => 'Tarih', 'work_order_number' => 'İş emri no', 'product_part_id' => 'Üretilecek',
        'order_item_id' => 'Sipariş kalemi', 'production_batch_id' => 'Parti', 'assigned_user_id' => 'Atanan operatör',
        'planned_start' => 'Planlanan başlangıç', 'planned_end' => 'Planlanan bitiş', 'started_at' => 'Başlama',
        'completed_at' => 'Bitiş', 'serial_number' => 'Seri no', 'work_order_id' => 'İş emri', 'shipped_at' => 'Sevk zamanı',
        'shipped_by_user_id' => 'Sevk eden', 'currency' => 'Para birimi', 'rate_date' => 'Kur tarihi', 'forex_buying' => 'Döviz alış',
        'forex_selling' => 'Döviz satış', 'source' => 'Kaynak', 'user_id' => 'Kullanıcı', 'unit_price' => 'Birim fiyat',
        'valid_from' => 'Geçerlilik başlangıcı', 'direction' => 'Yön', 'kind' => 'Tür', 'invoice_number' => 'Fatura no',
        'counterparty_number' => 'Karşı taraf belge no', 'original_invoice_id' => 'Orijinal fatura', 'invoice_date' => 'Fatura tarihi',
        'exchange_rate' => 'Kur', 'grand_total' => 'Genel toplam', 'grand_total_try' => 'TL karşılığı', 'invoice_id' => 'Fatura',
        'part_id' => 'Ürün', 'unit' => 'Birim', 'discount_rate' => 'İskonto (%)', 'vat_rate' => 'KDV (%)', 'line_total' => 'Satır toplamı',
        'bank_name' => 'Banka', 'branch' => 'Şube', 'iban' => 'IBAN', 'payment_date' => 'Tarih', 'method' => 'Yöntem',
        'cash_account_id' => 'Kasa/banka', 'cheque_id' => 'Çek/senet', 'amount' => 'Tutar', 'account_currency' => 'Cari para birimi',
        'account_amount' => 'Cari tutarı', 'number' => 'Numara', 'drawer' => 'Keşideci', 'production_date' => 'Üretim tarihi',
        'brand_id' => 'Marka', 'image_path' => 'Görsel', 'country_code' => 'Ülke', 'foreign_tax_id' => 'Yabancı vergi no', 'gtip_code' => 'GTİP', 'net_weight_kg' => 'Net ağırlık (kg)',
        'gross_weight_kg' => 'Brüt ağırlık (kg)', 'export_type' => 'İhracat türü', 'incoterm' => 'Teslim şekli',
        'transport_mode' => 'Gönderim şekli', 'package_count' => 'Kap sayısı', 'package_type' => 'Kap cinsi',
        'customs_declaration_number' => 'Gümrük beyannamesi no', 'customs_declaration_date' => 'Gümrük beyannamesi tarihi',
    ];

    /** Alan => [model sınıfı, gösterim alanı] */
    private const FOREIGN_KEYS = [
        'unit_id' => [Models\Unit::class, 'name'], 'brand_id' => [Models\Brand::class, 'name'], 'part_id' => [Models\Part::class, 'code'], 'parent_part_id' => [Models\Part::class, 'code'],
        'component_part_id' => [Models\Part::class, 'code'], 'product_part_id' => [Models\Part::class, 'code'],
        'motor_model_id' => [Models\MotorModel::class, 'code'], 'customer_id' => [Models\Customer::class, 'name'],
        'order_id' => [Models\Order::class, 'order_number'], 'production_batch_id' => [Models\ProductionBatch::class, 'batch_number'],
        'work_order_id' => [Models\WorkOrder::class, 'work_order_number'], 'assigned_user_id' => [Models\User::class, 'name'],
        'shipped_by_user_id' => [Models\User::class, 'name'], 'user_id' => [Models\User::class, 'name'],
        'cash_account_id' => [Models\CashAccount::class, 'name'], 'cheque_id' => [Models\Cheque::class, 'number'],
        'original_invoice_id' => [Models\Invoice::class, 'invoice_number'], 'invoice_id' => [Models\Invoice::class, 'invoice_number'],
    ];

    /** Kayıt türü => [alan => enum] (aynı alan adı farklı kayıtta farklı enum olabilir). */
    private const ENUMS = [
        'part' => ['type' => Enums\PartType::class],
        'motor_model' => ['phase' => Enums\MotorPhase::class],
        'customer' => ['default_currency' => Enums\Currency::class],
        'order' => ['status' => Enums\OrderStatus::class],
        'work_order' => ['status' => Enums\WorkOrderStatus::class],
        'motor_unit' => ['status' => Enums\MotorUnitStatus::class],
        'exchange_rate' => ['currency' => Enums\Currency::class, 'source' => Enums\ExchangeRateSource::class],
        'price' => ['currency' => Enums\Currency::class],
        'invoice' => [
            'direction' => Enums\InvoiceDirection::class, 'kind' => Enums\InvoiceKind::class, 'status' => Enums\InvoiceStatus::class, 'currency' => Enums\Currency::class,
            'export_type' => Enums\ExportType::class, 'incoterm' => Enums\Incoterm::class, 'transport_mode' => Enums\TransportMode::class, 'package_type' => Enums\PackageType::class,
        ],
        'cash_account' => ['kind' => Enums\CashAccountKind::class, 'currency' => Enums\Currency::class],
        'payment' => ['direction' => Enums\PaymentDirection::class, 'method' => Enums\PaymentMethod::class, 'status' => Enums\PaymentStatus::class, 'currency' => Enums\Currency::class, 'account_currency' => Enums\Currency::class],
        'cheque' => ['direction' => Enums\ChequeDirection::class, 'kind' => Enums\ChequeKind::class, 'status' => Enums\ChequeStatus::class, 'currency' => Enums\Currency::class],
    ];

    private const SUBJECT_TITLES = [
        'user' => 'username', 'unit' => 'name', 'brand' => 'name', 'part' => 'code', 'motor_model' => 'code', 'customer' => 'name',
        'order' => 'order_number', 'production_batch' => 'batch_number', 'work_order' => 'work_order_number',
        'motor_unit' => 'serial_number', 'cash_account' => 'name', 'cheque' => 'number',
    ];

    public static function subjectType(?string $type): string
    {
        return self::SUBJECT_LABELS[$type] ?? ($type ?? '—');
    }

    public static function event(Activity $activity): string
    {
        if ($activity->description === 'roles_updated') {
            return 'Roller değişti';
        }

        return self::EVENT_LABELS[$activity->event] ?? ($activity->event ?? $activity->description);
    }

    /** Kaydın adı (ör. iş emri no); kayıt silinmişse "#id (silinmiş)". */
    public static function subject(Activity $activity): string
    {
        if ($activity->subject_type === null) {
            return $activity->properties['username'] ?? '—';
        }

        $model = $activity->subject;

        if ($model === null) {
            return "#{$activity->subject_id} (silinmiş)";
        }

        if ($model instanceof Models\Invoice) {
            return $model->displayNumber();
        }

        $field = self::SUBJECT_TITLES[$activity->subject_type] ?? null;

        return $field !== null ? (string) $model->{$field} : "#{$model->getKey()}";
    }

    public static function attribute(string $attribute): string
    {
        return self::ATTRIBUTE_LABELS[$attribute] ?? $attribute;
    }

    /**
     * Değişen alanlar: [alan etiketi, eski, yeni].
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    /** Değişen alanların yalnızca etiketleri (liste ekranı; değerler ve bağlı kayıtlar yüklenmez). */
    public static function changedFields(Activity $activity): string
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $keys = array_unique([...array_keys($changes['old'] ?? []), ...array_keys($changes['attributes'] ?? [])]);

        return implode(', ', array_map(self::attribute(...), $keys)) ?: '—';
    }

    public static function changes(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $new = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];

        return collect(array_unique([...array_keys($old), ...array_keys($new)]))
            ->map(fn (string $key) => [
                'field' => self::attribute($key),
                'old' => array_key_exists($key, $old) ? self::value($activity->subject_type, $key, $old[$key]) : '—',
                'new' => array_key_exists($key, $new) ? self::value($activity->subject_type, $key, $new[$key]) : '—',
            ])
            ->values()
            ->all();
    }

    public static function value(?string $subjectType, string $attribute, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if ($attribute === 'roles' && is_array($value)) {
            return implode(', ', array_map(fn ($role) => Enums\Role::tryFrom($role)?->label() ?? $role, $value));
        }

        if (is_bool($value)) {
            return $value ? 'Evet' : 'Hayır';
        }

        if ($enum = self::ENUMS[$subjectType][$attribute] ?? null) {
            /** @var class-string<BackedEnum> $enum */
            $case = $enum::tryFrom($value);

            return $case !== null && method_exists($case, 'label') ? $case->label() : (string) $value;
        }

        if ($attribute === 'country_code' && is_string($value)) {
            return Country::name($value) ?? $value;
        }

        if ([$class, $field] = self::FOREIGN_KEYS[$attribute] ?? null) {
            /** @var Model|null $model */
            // Aynı istekte aynı kayıt bir kez okunur (detayda eski ve yeni değer çoğu zaman aynı kayda gider).
            $model = self::$related[$class][$value] ??= $class::query()->find($value);

            if ($model === null) {
                return "#{$value} (silinmiş)";
            }

            return filled($model->{$field}) ? (string) $model->{$field} : "#{$value}";
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(T| |$)/', $value)) {
            $date = CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));

            return strlen($value) === 10 || $date->format('H:i:s') === '00:00:00' ? $date->format('d.m.Y') : $date->format('d.m.Y H:i');
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** Morph takma adından model sınıfı. */
    public static function modelClass(string $type): ?string
    {
        return Relation::getMorphedModel($type);
    }
}
