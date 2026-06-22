<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Filament\Support\AbsenceUi;
use App\Models\Absence;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AbsencesRelationManager extends RelationManager
{
    protected static string $relationship = 'absences';

    protected static ?string $title = 'Abwesenheiten';

    public function form(Form $form): Form
    {
        return $form->schema(AbsenceUi::formSchema());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->defaultSort('start_date', 'desc')
            ->columns(AbsenceUi::columns())
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                ...AbsenceUi::decisionActions(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
