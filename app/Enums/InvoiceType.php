<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Belge türü = yön + tür. Bizim kestiğimiz belgeler (satış faturası, alış iadesi) FTR numarası alır; karşı tarafın
 * kestikleri (alış faturası, satış iadesi) karşı tarafın belge numarasıyla kaydedilir.
 */
enum InvoiceType: string implements HasLabel
{
    case SalesInvoice = 'sales_invoice';
    case PurchaseInvoice = 'purchase_invoice';
    case SalesReturn = 'sales_return';
    case PurchaseReturn = 'purchase_return';

    public static function of(InvoiceDirection $direction, InvoiceKind $kind): self
    {
        return match ([$direction, $kind]) {
            [InvoiceDirection::Sales, InvoiceKind::Normal] => self::SalesInvoice,
            [InvoiceDirection::Purchase, InvoiceKind::Normal] => self::PurchaseInvoice,
            [InvoiceDirection::Sales, InvoiceKind::Return] => self::SalesReturn,
            [InvoiceDirection::Purchase, InvoiceKind::Return] => self::PurchaseReturn,
        };
    }

    public function direction(): InvoiceDirection
    {
        return in_array($this, [self::SalesInvoice, self::SalesReturn], true) ? InvoiceDirection::Sales : InvoiceDirection::Purchase;
    }

    public function kind(): InvoiceKind
    {
        return in_array($this, [self::SalesInvoice, self::PurchaseInvoice], true) ? InvoiceKind::Normal : InvoiceKind::Return;
    }

    public function label(): string
    {
        return match ($this) {
            self::SalesInvoice => 'Satış faturası',
            self::PurchaseInvoice => 'Alış faturası',
            self::SalesReturn => 'Satış iadesi',
            self::PurchaseReturn => 'Alış iadesi',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** Belgeyi biz mi kesiyoruz? (FTR numarası alır.) */
    public function issuedByUs(): bool
    {
        return in_array($this, [self::SalesInvoice, self::PurchaseReturn], true);
    }

    /** Cari müşteri mi (satış tarafı), tedarikçi mi? */
    public function requiresCustomer(): bool
    {
        return $this->direction() === InvoiceDirection::Sales;
    }
}
