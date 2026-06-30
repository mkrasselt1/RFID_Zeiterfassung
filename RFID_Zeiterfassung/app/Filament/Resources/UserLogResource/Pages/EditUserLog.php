<?php

namespace App\Filament\Resources\UserLogResource\Pages;

use App\Filament\Resources\UserLogResource;
use App\Services\WorktimeService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUserLog extends EditRecord
{
    protected static string $resource = UserLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->after(fn () => $this->recalc()),
        ];
    }

    protected function afterSave(): void
    {
        $this->recalc();
    }

    /** Rebuild the ledger day affected by the corrected stamping. */
    private function recalc(): void
    {
        app(WorktimeService::class)->recalculateForLog($this->record);
    }
}
