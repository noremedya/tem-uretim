<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\Locked;

/**
 * Düzenleme sayfaları için optimistic locking.
 *
 * Livewire her istekte kaydı veritabanından yeniden okur; bu yüzden kayıttaki lock_version her zaman
 * güncel olur ve çakışmayı gizler. Formun açıldığı andaki sürüm burada saklanır ve kaydederken
 * servise "beklenen sürüm" olarak verilir. #[Locked]: istemci bu değeri değiştiremez.
 */
trait TracksLockVersion
{
    #[Locked]
    public ?int $lockVersion = null;

    protected function fillForm(): void
    {
        $this->lockVersion = $this->getRecord()->lock_version;

        parent::fillForm();
    }
}
