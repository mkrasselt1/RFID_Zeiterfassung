<?php

namespace App\Filament\Resources\CardholderResource\Pages;

use App\Filament\Resources\CardholderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCardholder extends EditRecord
{
    protected static string $resource = CardholderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
