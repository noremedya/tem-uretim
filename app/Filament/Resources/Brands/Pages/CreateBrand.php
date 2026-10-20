<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Brands\BrandResource;
use App\Services\BrandService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBrand extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = BrandResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(BrandService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
