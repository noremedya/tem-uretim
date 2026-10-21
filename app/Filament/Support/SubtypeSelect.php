<?php

namespace App\Filament\Support;

use App\Enums\PartType;
use App\Filament\Resources\PartSubtypes\Schemas\PartSubtypeForm;
use App\Models\PartSubtype;
use App\Services\PartSubtypeService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Auth;

/**
 * Alt tür seçimi: yalnızca verilen ana türün aktif alt türleri (kayıttaki pasif alt tür listede kalır); "+" ile formdan
 * çıkmadan yeni alt tür (ana tür formdakinden gelir; yalnızca alt tür yönetme yetkisi olanlar görür).
 */
final class SubtypeSelect
{
    /**
     * @param  Closure(mixed ...): ?PartType  $type  Formdaki ana tür (Get ve kayıt enjekte edilebilir).
     * @param  Closure(mixed ...): ?int  $current  Kayıttaki alt tür (pasifse de listelenir).
     */
    public static function make(string $name, Closure $type, Closure $current): Select
    {
        return Select::make($name)
            ->label('Alt tür')
            ->placeholder('Alt tür yok')
            ->options(function (Select $component) use ($type, $current): array {
                $partType = $component->evaluate($type);
                $currentId = $component->evaluate($current);

                if ($partType === null) {
                    return [];
                }

                return PartSubtype::query()
                    ->where('type', $partType->value)
                    ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $currentId))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            })
            ->searchable()
            ->createOptionForm(PartSubtypeForm::fields(withType: false))
            ->createOptionUsing(function (array $data, Select $component) use ($type): int {
                return app(PartSubtypeService::class)->create([...$data, 'type' => $component->evaluate($type)])->getKey();
            })
            ->createOptionAction(fn (Action $action) => $action->modalHeading('Yeni alt tür')
                ->visible(fn (Select $component): bool => (Auth::user()?->can('create', PartSubtype::class) ?? false) && $component->evaluate($type) !== null));
    }
}
