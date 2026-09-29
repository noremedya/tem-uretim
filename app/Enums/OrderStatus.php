<?php

namespace App\Enums;

use App\Exceptions\BusinessRuleException;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Sipariş durumu. open/partial/completed üretime göre hesaplanır (Aşama 4); cancelled elle yapılır.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Partial = 'partial';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::Partial => 'Kısmi',
            self::Completed => 'Tamamlandı',
            self::Cancelled => 'İptal',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Partial => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    /**
     * İzinli geçişler (CLAUDE.md bölüm 5). Üretim geri alındığında gerekebilecek geri geçişler Aşama 4'te.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Partial, self::Completed, self::Cancelled],
            self::Partial => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @throws BusinessRuleException İzinsiz geçişte. */
    public function ensureCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new BusinessRuleException(sprintf(
                'Sipariş durumu "%s" iken "%s" durumuna geçilemez.',
                $this->label(),
                $to->label(),
            ));
        }
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::Cancelled);
    }

    /** Termin, müşteri sipariş no ve notlar değiştirilebilir mi? */
    public function isEditable(): bool
    {
        return in_array($this, [self::Open, self::Partial], true);
    }

    /** Müşteri ve kalemler değiştirilebilir mi? */
    public function allowsItemChanges(): bool
    {
        return $this === self::Open;
    }
}
