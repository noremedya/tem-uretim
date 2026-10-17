<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/** Ana ekranda büyük "Hızlı İşlem" butonu (operatör ana ekranı, CLAUDE.md bölüm 6). */
class QuickScanShortcut extends Widget
{
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-scan-shortcut';
}
