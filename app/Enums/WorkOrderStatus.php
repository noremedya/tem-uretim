<?php

namespace App\Enums;

use App\Exceptions\BusinessRuleException;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planlandı',
            self::InProgress => 'Üretimde',
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
            self::Planned => 'info',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    /**
     * İzinli geçişler (CLAUDE.md bölüm 5). completed → in_progress yalnızca geri alma akışıyla yapılır.
     * Kimin yapabileceği WorkOrderService::canTransition'dadır.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Planned],
            self::Completed => [self::InProgress],
            self::Cancelled => [],
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
                'İş emri "%s" iken "%s" durumuna geçilemez.',
                $this->label(),
                $to->label(),
            ));
        }
    }

    /** Adet değiştirilebilir mi? */
    public function allowsQuantityChange(): bool
    {
        return in_array($this, [self::Planned, self::InProgress], true);
    }

    /** Tamamlanmamış ve iptal edilmemiş (açık) mı? */
    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::InProgress], true);
    }
}
