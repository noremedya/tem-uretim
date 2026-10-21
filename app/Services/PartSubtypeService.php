<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\PartSubtype;
use Illuminate\Support\Facades\DB;

/**
 * Alt türler: ad aynı ana türde tekildir (büyük/küçük harf ve Türkçe I/ı önemsiz; veritabanında da). Kullanılan alt
 * türün ana türü değiştirilemez (veritabanında da). Silinmez, pasifleştirilir.
 */
class PartSubtypeService
{
    public function create(array $data): PartSubtype
    {
        return DB::transaction(function () use ($data): PartSubtype {
            $subtype = new PartSubtype(['type' => $this->type($data['type'] ?? null), 'name' => $data['name'] ?? '']);
            $this->ensureValid($subtype);
            $subtype->save();

            return $subtype;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(PartSubtype $subtype, array $data, int $expectedLockVersion): PartSubtype
    {
        return DB::transaction(function () use ($subtype, $data, $expectedLockVersion): PartSubtype {
            $subtype->fill([
                'type' => array_key_exists('type', $data) ? $this->type($data['type']) : $subtype->type,
                'name' => $data['name'] ?? $subtype->name,
            ]);

            if ($subtype->isDirty('type') && $subtype->parts()->exists()) {
                throw new BusinessRuleException('Ürünlerde kullanılan alt türün ana türü değiştirilemez.');
            }

            $this->ensureValid($subtype);
            $subtype->saveExpectingVersion($expectedLockVersion);

            return $subtype;
        });
    }

    /** Pasif alt tür yeni ürüne seçilemez; mevcut ürünlerde kalır. */
    public function deactivate(PartSubtype $subtype): PartSubtype
    {
        $subtype->is_active = false;
        $subtype->save();

        return $subtype;
    }

    public function activate(PartSubtype $subtype): PartSubtype
    {
        $subtype->is_active = true;
        $subtype->save();

        return $subtype;
    }

    private function type(mixed $value): PartType
    {
        $type = $value instanceof PartType ? $value : PartType::tryFrom((string) $value);

        return $type ?? throw new BusinessRuleException('Ana tür seçilmelidir.');
    }

    private function ensureValid(PartSubtype $subtype): void
    {
        if ($subtype->name === '') {
            throw new BusinessRuleException('Alt tür adı zorunludur.');
        }

        $duplicate = PartSubtype::query()
            ->where('type', $subtype->type->value)
            ->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$subtype->name])
            ->when($subtype->exists, fn ($q) => $q->whereKeyNot($subtype->getKey()))
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleException("{$subtype->type->label()} altında \"{$subtype->name}\" alt türü zaten var.");
        }
    }
}
