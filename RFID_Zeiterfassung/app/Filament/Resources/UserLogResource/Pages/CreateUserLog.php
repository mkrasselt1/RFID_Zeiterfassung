<?php

namespace App\Filament\Resources\UserLogResource\Pages;

use App\Filament\Resources\UserLogResource;
use App\Models\Cardholder;
use App\Services\WorktimeService;
use Filament\Resources\Pages\CreateRecord;

class CreateUserLog extends CreateRecord
{
    protected static string $resource = UserLogResource::class;

    /** Derive owner + device fields from the chosen card. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $card = Cardholder::where('card_uid', $data['card_uid'] ?? '')->first();
        $data['employee_id'] = $card?->employee_id;
        $data['device_uid'] = $card?->device_uid ?? 'Korrektur';
        $data['device_dep'] = $card?->device_dep ?? 'Korrektur';
        $data['timeout'] = $data['timeout'] ?? 0;
        $data['card_out'] = $data['card_out'] ?? true;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(WorktimeService::class)->recalculateForLog($this->record);
    }
}
