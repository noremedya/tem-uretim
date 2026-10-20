<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;

/** Markalar: ad tekildir (büyük/küçük harf ve Türkçe I/ı önemsiz; veritabanında da). Silinmez, pasifleştirilir. */
class BrandService
{
    public function create(array $data): Brand
    {
        return DB::transaction(function () use ($data): Brand {
            $brand = new Brand(['name' => $data['name'] ?? '']);
            $this->ensureValid($brand);
            $brand->save();

            return $brand;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Brand $brand, array $data, int $expectedLockVersion): Brand
    {
        return DB::transaction(function () use ($brand, $data, $expectedLockVersion): Brand {
            $brand->fill(['name' => $data['name'] ?? $brand->name]);
            $this->ensureValid($brand);
            $brand->saveExpectingVersion($expectedLockVersion);

            return $brand;
        });
    }

    /** Pasif marka yeni ürüne seçilemez; mevcut ürünlerde kalır. */
    public function deactivate(Brand $brand): Brand
    {
        $brand->is_active = false;
        $brand->save();

        return $brand;
    }

    public function activate(Brand $brand): Brand
    {
        $brand->is_active = true;
        $brand->save();

        return $brand;
    }

    private function ensureValid(Brand $brand): void
    {
        if ($brand->name === '') {
            throw new BusinessRuleException('Marka adı zorunludur.');
        }

        $duplicate = Brand::query()
            ->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$brand->name])
            ->when($brand->exists, fn ($q) => $q->whereKeyNot($brand->getKey()))
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleException("\"{$brand->name}\" markası zaten var.");
        }
    }
}
