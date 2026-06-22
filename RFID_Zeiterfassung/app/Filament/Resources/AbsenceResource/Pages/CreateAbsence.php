<?php

namespace App\Filament\Resources\AbsenceResource\Pages;

use App\Filament\Resources\AbsenceResource;
use App\Models\Absence;
use Filament\Resources\Pages\CreateRecord;

class CreateAbsence extends CreateRecord
{
    protected static string $resource = AbsenceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Employees always file for themselves; new requests start pending.
        if (! auth()->user()->canManagePeople()) {
            $data['employee_id'] = auth()->id();
        }
        $data['employee_id'] ??= auth()->id();
        $data['status'] = Absence::STATUS_PENDING;

        return $data;
    }
}
