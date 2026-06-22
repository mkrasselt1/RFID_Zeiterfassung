<?php

namespace App\Filament\Resources\CardholderResource\Pages;

use App\Filament\Resources\CardholderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCardholders extends ListRecords
{
    protected static string $resource = CardholderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
