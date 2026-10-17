<?php

namespace App\Filament\Resources\Cheques\Pages;

use App\Filament\Resources\Cheques\ChequeResource;
use Filament\Resources\Pages\ListRecords;

class ListCheques extends ListRecords
{
    protected static string $resource = ChequeResource::class;
}
