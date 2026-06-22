<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AbsenceResource\Pages;
use App\Filament\Support\AbsenceUi;
use App\Models\Absence;
use App\Models\Employee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AbsenceResource extends Resource
{
    protected static ?string $model = Absence::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Abwesenheiten';

    protected static ?string $modelLabel = 'Abwesenheit';

    protected static ?string $pluralModelLabel = 'Abwesenheiten';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Managers may file on behalf of anyone; employees only for themselves.
            Forms\Components\Select::make('employee_id')->label('Mitarbeiter')
                ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))
                ->default(fn () => auth()->id())
                ->searchable()
                ->required()
                ->visible(fn () => auth()->user()?->canManagePeople() ?? false),
            ...AbsenceUi::formSchema(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $isManager = auth()->user()?->canManagePeople() ?? false;

        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')->label('Mitarbeiter')
                    ->searchable()->sortable()->visible($isManager),
                ...AbsenceUi::columns(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(Absence::STATUSES),
                Tables\Filters\SelectFilter::make('type')->label('Art')->options(Absence::TYPES),
            ])
            ->actions([
                ...AbsenceUi::decisionActions(),
                Tables\Actions\Action::make('cancel')->label('Stornieren')
                    ->icon('heroicon-o-x-circle')->color('gray')
                    ->visible(fn (Absence $r) => $r->status === Absence::STATUS_PENDING
                        && ($r->employee_id === auth()->id() || (auth()->user()?->canManagePeople() ?? false)))
                    ->requiresConfirmation()
                    ->action(fn (Absence $r) => $r->update(['status' => Absence::STATUS_CANCELLED])),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Absence $r) => $r->status === Absence::STATUS_PENDING),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if ($user && ! $user->canManagePeople()) {
            $query->where('employee_id', $user->id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAbsences::route('/'),
            'create' => Pages\CreateAbsence::route('/create'),
            'edit' => Pages\EditAbsence::route('/{record}/edit'),
        ];
    }
}
