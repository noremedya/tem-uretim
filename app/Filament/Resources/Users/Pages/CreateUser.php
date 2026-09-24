<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Users\UserResource;
use App\Services\UserService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(UserService::class)->create($data));
    }
}
