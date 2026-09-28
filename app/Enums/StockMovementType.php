<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Stok hareket tipleri. İşaret kuralı veritabanında stock_movements_type_sign_check ile de zorlanır.
 */
enum StockMovementType: string implements HasColor, HasLabel
{
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case ProductionConsumption = 'production_consumption';
    case CountAdjustment = 'count_adjustment';
    // Üretimden/müşteriden depoya dönüş. Tedarikçiye iade StockOut + açıklamayla yapılır.
    case Return = 'return';
    // Bir hareketin ters kaydı (hata düzeltme).
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::StockIn => 'Giriş',
            self::StockOut => 'Çıkış',
            self::ProductionConsumption => 'Üretim sarfı',
            self::CountAdjustment => 'Sayım düzeltme',
            self::Return => 'İade',
            self::Correction => 'Ters kayıt',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::StockIn, self::Return => 'success',
            self::StockOut, self::ProductionConsumption => 'danger',
            self::CountAdjustment => 'warning',
            self::Correction => 'gray',
        };
    }

    /** 1: yalnızca artırır, -1: yalnızca azaltır, 0: iki yönde de olabilir. */
    public function sign(): int
    {
        return match ($this) {
            self::StockIn, self::Return => 1,
            self::StockOut, self::ProductionConsumption => -1,
            self::CountAdjustment, self::Correction => 0,
        };
    }

    public function requiresDescription(): bool
    {
        return in_array($this, [self::StockOut, self::CountAdjustment, self::Correction], true);
    }

    /** Pasif parçada yapılabilir mi? Pasif parçaya yalnızca sayım yapılır. */
    public function allowedOnInactivePart(): bool
    {
        return $this === self::CountAdjustment;
    }

    /** Stok işlemi sayfasında elle yapılabilen tipler. */
    public static function manualCases(): array
    {
        return [self::StockIn, self::StockOut, self::CountAdjustment, self::Return];
    }
}
