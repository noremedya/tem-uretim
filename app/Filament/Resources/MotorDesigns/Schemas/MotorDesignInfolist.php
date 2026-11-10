<?php

namespace App\Filament\Resources\MotorDesigns\Schemas;

use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Models\MotorDesign;
use App\Support\Quantity;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class MotorDesignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 5])->schema([
                        ImageEntry::make('image')->hiddenLabel()
                            ->state(fn (MotorDesign $record): ?string => $record->imageUrl())
                            ->visible(fn (MotorDesign $record): bool => $record->image_path !== null)
                            ->imageHeight(220)
                            ->url(fn (MotorDesign $record): ?string => $record->imageUrl(), shouldOpenInNewTab: true)
                            ->columnSpan(['default' => 1, 'lg' => 2]),
                        Grid::make(['default' => 2, 'md' => 4])
                            ->columnSpan(fn (MotorDesign $record): array => ['default' => 1, 'lg' => $record->image_path !== null ? 3 : 5])
                            ->schema([
                                TextEntry::make('code')->label('Kod'),
                                TextEntry::make('name')->label('Ad'),
                                TextEntry::make('template.name')->label('Motor tipi')->placeholder('Elle (şablonsuz)'),
                                IconEntry::make('is_active')->label('Aktif')->boolean(),
                                TextEntry::make('subtype.name')->label('Alt tür')->placeholder('—'),
                                TextEntry::make('phase')->label('Faz')->placeholder('—'),
                                TextEntry::make('power_kw')->label('Güç')->formatStateUsing(fn ($state): string => Quantity::format($state, 'kW'))->placeholder('—'),
                                TextEntry::make('speed_rpm')->label('Devir')->formatStateUsing(fn ($state): string => Number::format($state).' d/dk')->placeholder('—'),
                                TextEntry::make('pole_count')->label('Kutup sayısı')->placeholder('—'),
                                TextEntry::make('voltage')->label('Gerilim')->placeholder('—'),
                                TextEntry::make('frame_size')->label('Gövde ölçüsü')->placeholder('—'),
                                TextEntry::make('mounting_type')->label('Montaj şekli')->placeholder('—'),
                                TextEntry::make('motorModel.code')->label('Oluşturulan motor modeli')->placeholder('Henüz yok')
                                    ->url(fn (MotorDesign $record): ?string => $record->motor_model_id && auth()->user()?->can('view', $record->motorModel)
                                        ? MotorModelResource::getUrl('view', ['record' => $record->motor_model_id]) : null),
                                TextEntry::make('description')->label('Açıklama')->placeholder('—')->columnSpanFull(),
                            ]),
                    ]),
                ]),
            Section::make('Montaj çizimi')
                ->columnSpanFull()
                ->collapsible()
                ->visible(fn (MotorDesign $record): bool => $record->template?->drawingConfig() !== null)
                ->schema([
                    View::make('filament.pages.motor-builder.drawing')
                        ->viewData(fn (MotorDesign $record): array => self::drawing($record)),
                ]),
        ]);
    }

    /** Çizim işaretleri: şablon satırına bileşen girildiyse yeşil. @return array<string, mixed> */
    private static function drawing(MotorDesign $record): array
    {
        $drawing = $record->template?->drawingConfig();

        if ($drawing === null) {
            return ['drawing' => null];
        }

        $filled = $record->items()->whereNotNull('bom_template_line_id')->pluck('bom_template_line_id')->flip();
        $markers = [];

        foreach ($record->template->lines as $line) {
            if ($line->drawing_number === null) {
                continue;
            }

            $number = $line->drawing_number;
            $markers[$number] ??= ['label' => $line->drawingLabel(), 'lines' => [], 'selected' => false, 'target' => ''];
            $markers[$number]['lines'][] = $line->label;
            $markers[$number]['selected'] = $markers[$number]['selected'] || $filled->has($line->getKey());
        }

        $points = [];

        foreach ($markers as $number => $marker) {
            foreach ($drawing['markers'][$number] ?? [] as [$x, $y]) {
                $points[] = [...$marker, 'left' => round($x / $drawing['width'] * 100, 2), 'top' => round($y / $drawing['height'] * 100, 2)];
            }
        }

        return ['drawing' => $drawing, 'points' => $points];
    }
}
