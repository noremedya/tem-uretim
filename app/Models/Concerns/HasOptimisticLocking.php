<?php

namespace App\Models\Concerns;

use App\Exceptions\StaleModelException;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * lock_version ile optimistic locking.
 *
 * Kontrol tek atomik sorguyla yapılır:
 *   UPDATE ... SET ..., lock_version = :beklenen + 1 WHERE id = :id AND lock_version = :beklenen
 * Etkilenen satır 0 ise StaleModelException fırlatılır. Önce okuyup karşılaştırma yapılmaz.
 *
 * "Beklenen sürüm" modelin o anki lock_version değeridir. Normalde bu, kaydın okunduğu andaki
 * değerdir; bir form gibi kaydın yeniden okunduğu akışlarda saveExpectingVersion() ile formun
 * açıldığı andaki sürüm verilir.
 */
trait HasOptimisticLocking
{
    public function initializeHasOptimisticLocking(): void
    {
        $this->mergeCasts(['lock_version' => 'integer']);

        if (! array_key_exists('lock_version', $this->attributes)) {
            $this->attributes['lock_version'] = 0;
        }
    }

    /**
     * Kaydı, verilen sürüm beklenerek kaydeder. Kirli alan olmasa bile UPDATE çalışır; böylece
     * sürüm her durumda kontrol edilir ve artırılır (ör. yalnızca ilişkiler değiştiğinde).
     */
    public function saveExpectingVersion(int $expectedVersion, array $options = []): bool
    {
        $previousVersion = $this->attributes['lock_version'] ?? null;
        $previousOriginal = $this->original['lock_version'] ?? null;

        $this->setAttribute('lock_version', $expectedVersion);

        if ($this->exists) {
            // lock_version'ı kirli işaretle ki başka değişen alan olmasa da UPDATE (ve kontrol) çalışsın.
            $this->original['lock_version'] = null;
        }

        try {
            return $this->save($options);
        } catch (Throwable $exception) {
            // Başarısız denemede model okunduğu hâldeki sürüme döner.
            $this->attributes['lock_version'] = $previousVersion;
            $this->original['lock_version'] = $previousOriginal;

            throw $exception;
        }
    }

    /**
     * Eloquent'in performUpdate'i ile aynı akış; tek fark sürüm koşulu ve etkilenen satır kontrolü.
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $expectedVersion = (int) $this->getAttribute('lock_version');
            $dirty['lock_version'] = $expectedVersion + 1;

            $affected = $this->setKeysForSaveQuery($query)
                ->where('lock_version', $expectedVersion)
                ->update($dirty);

            if ($affected === 0) {
                throw new StaleModelException($this);
            }

            $this->setAttribute('lock_version', $expectedVersion + 1);

            $this->refreshSavedAttributes();

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }
}
