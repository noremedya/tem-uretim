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
    // Üretim firesi (net sarfın üstüne, ayrı izlenir).
    case ProductionScrap = 'production_scrap';
    // Üretilen yarı mamul/mamulün stoğa girişi.
    case ProductionOutput = 'production_output';
    // Mamulün (motor) sevki.
    case Shipment = 'shipment';
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
            self::ProductionScrap => 'Üretim firesi',
            self::ProductionOutput => 'Üretimden giriş',
            self::Shipment => 'Sevk',
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
            self::StockIn, self::Return, self::ProductionOutput => 'success',
            self::StockOut, self::ProductionConsumption, self::ProductionScrap, self::Shipment => 'danger',
            self::CountAdjustment => 'warning',
            self::Correction => 'gray',
        };
    }

    /** 1: yalnızca artırır, -1: yalnızca azaltır, 0: iki yönde de olabilir. */
    public function sign(): int
    {
        return match ($this) {
            self::StockIn, self::Return, self::ProductionOutput => 1,
            self::StockOut, self::ProductionConsumption, self::ProductionScrap, self::Shipment => -1,
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

    /**
     * Üretim/sevk akışına bağlı tipler: elle ters kaydedilemez, yalnızca iş emri geri alma akışı ters kaydeder.
     */
    public function isProductionFlow(): bool
    {
        return in_array($this, [self::ProductionConsumption, self::ProductionScrap, self::ProductionOutput, self::Shipment], true);
    }

    /** Mamul kartında yapılabilir mi? Mamul stoğu yalnızca üretim ve sevkle değişir (geri almada ters kayıt). */
    public function allowedOnFinishedGood(): bool
    {
        return in_array($this, [self::ProductionOutput, self::Shipment, self::Correction], true);
    }

    /** Stok işlemi sayfasında elle yapılabilen tipler. */
    public static function manualCases(): array
    {
        return [self::StockIn, self::StockOut, self::CountAdjustment, self::Return];
    }
}
