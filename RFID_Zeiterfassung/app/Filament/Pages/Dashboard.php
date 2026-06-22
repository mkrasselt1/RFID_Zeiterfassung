<?php

namespace App\Filament\Pages;

use App\Models\Employee;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * Dashboard with an employee picker for managers, so HR/Admin can view any
 * employee's stats/charts. Employees only ever see their own.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Form $form): Form
    {
        if (! (auth()->user()?->canManagePeople() ?? false)) {
            return $form->schema([]);
        }

        return $form->schema([
            Select::make('employee_id')
                ->label('Mitarbeiter')
                ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))
                ->default(auth()->id())
                ->searchable()
                ->live(),
        ]);
    }
}
