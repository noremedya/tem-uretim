<?php

namespace App\Filament\Resources\MotorDesigns\Schemas;

use App\Enums\MotorPhase;
use App\Enums\PartType;
use App\Filament\Pages\DesignBuilder;
use App\Models\MotorDesign;
use App\Models\PartSubtype;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MotorDesignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->description('Deneme reçetesi: stoktan düşmez, iş emri açılamaz. Bileşenleri kaydettikten sonra aşağıdan ekleyin.')
                ->schema([
                    TextInput::make('code')->label('Kod')->required()->maxLength(50),
                    TextInput::make('name')->label('Ad')->required()->maxLength(150),
                    DesignBuilder::imageUpload(),
                    Textarea::make('description')->label('Açıklama')->rows(3),
                ]),
            Section::make('Motor bilgileri')
                ->description('İsteğe bağlı; tasarım motor modeline çevrilirken modele aktarılır.')
                ->columns(4)
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    Select::make('subtype_id')->label('Alt tür')->placeholder('Alt tür yok')
                        ->options(fn (?MotorDesign $record): array => PartSubtype::query()->where('type', PartType::FinishedGood->value)
                            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $record?->subtype_id))->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('phase')->label('Faz')->options(MotorPhase::class),
                    TextInput::make('power_kw')->label('Güç')->suffix('kW')->inputMode('decimal'),
                    TextInput::make('speed_rpm')->label('Devir')->suffix('d/dk')->integer()->minValue(1),
                    TextInput::make('pole_count')->label('Kutup sayısı')->integer()->minValue(1)->maxValue(32767),
                    TextInput::make('voltage')->label('Gerilim')->maxLength(50),
                    TextInput::make('frame_size')->label('Gövde ölçüsü')->maxLength(50),
                    TextInput::make('mounting_type')->label('Montaj şekli')->maxLength(50),
                ]),
        ]);
    }
}
