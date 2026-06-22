<?php

namespace App\Filament\Resources\UserLogResource\Pages;

use App\Filament\Resources\UserLogResource;
use App\Models\Cardholder;
use App\Services\WorktimeService;
use Filament\Resources\Pages\CreateRecord;

class CreateUserLog extends CreateRecord
{
    protected static string $resource = UserLogResource::class;

    /** Fill the legacy denormalized fields from the chosen card. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $card = Cardholder::where('card_uid', $data['card_uid'] ?? '')->first();
        $data['username'] = $card?->username ?? 'Korrektur';
        $data['serialnumber'] = $card?->serialnumber ?? 0;
        $data['device_uid'] = $card?->device_uid ?? 'Korrektur';
        $data['device_dep'] = $card?->device_dep ?? 'Korrektur';
        $data['timeout'] = $data['timeout'] ?? 0;
        $data['card_out'] = $data['card_out'] ?? true;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(WorktimeService::class)->recalculateForCardDate(
            $this->record->card_uid,
            $this->record->checkindate,
        );
    }
}
