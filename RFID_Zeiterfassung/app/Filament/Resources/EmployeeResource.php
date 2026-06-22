<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Mitarbeiter';

    protected static ?string $modelLabel = 'Mitarbeiter';

    protected static ?string $pluralModelLabel = 'Mitarbeiter';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Konto')->schema([
                Forms\Components\TextInput::make('name')->label('Name')->required(),
                Forms\Components\TextInput::make('email')->label('E-Mail')->email()->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('password')->label('Passwort')->password()
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText('Beim Bearbeiten leer lassen, um das Passwort zu behalten.'),
                Forms\Components\Select::make('role')->label('Rolle')
                    ->options([
                        Employee::ROLE_EMPLOYEE => 'Mitarbeiter',
                        Employee::ROLE_SUPERVISOR => 'Vorgesetzter',
                        Employee::ROLE_HR => 'Personal (HR)',
                        Employee::ROLE_ADMIN => 'Administrator',
                    ])
                    ->default(Employee::ROLE_EMPLOYEE)
                    ->required(),
                Forms\Components\Toggle::make('is_active')->label('Aktiv')->default(true),
            ])->columns(2),
            Forms\Components\Section::make('Details')->schema([
                Forms\Components\TextInput::make('personnel_number')->label('Personalnummer'),
                Forms\Components\Select::make('supervisor_id')->label('Vorgesetzter')
                    ->options(fn (?Employee $record) => Employee::query()
                        ->whereIn('role', [Employee::ROLE_SUPERVISOR, Employee::ROLE_HR, Employee::ROLE_ADMIN])
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Nur Vorgesetzte, Personal oder Admins wählbar.'),
                Forms\Components\Select::make('gender')->label('Geschlecht')
                    ->options(['Male' => 'Männlich', 'Female' => 'Weiblich', 'None' => 'Keine Angabe']),
                Forms\Components\TextInput::make('calendar_id')->label('Google Kalender-ID'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('E-Mail')->searchable(),
                Tables\Columns\TextColumn::make('personnel_number')->label('Pers.-Nr.')->toggleable(),
                Tables\Columns\TextColumn::make('role')->label('Rolle')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Employee::ROLE_ADMIN => 'Administrator',
                        Employee::ROLE_HR => 'Personal',
                        Employee::ROLE_SUPERVISOR => 'Vorgesetzter',
                        default => 'Mitarbeiter',
                    })
                    ->color(fn (string $state) => match ($state) {
                        Employee::ROLE_ADMIN => 'danger',
                        Employee::ROLE_HR => 'warning',
                        Employee::ROLE_SUPERVISOR => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('cards_count')->label('Karten')->counts('cards'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktiv')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->label('Rolle')->options([
                    Employee::ROLE_EMPLOYEE => 'Mitarbeiter',
                    Employee::ROLE_SUPERVISOR => 'Vorgesetzter',
                    Employee::ROLE_HR => 'Personal',
                    Employee::ROLE_ADMIN => 'Administrator',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktiv'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CardsRelationManager::class,
            RelationManagers\ContractsRelationManager::class,
            RelationManagers\AbsencesRelationManager::class,
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManagePeople() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
