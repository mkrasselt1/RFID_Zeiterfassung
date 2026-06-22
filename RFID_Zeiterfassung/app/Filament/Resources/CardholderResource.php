<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ManagerOnly;
use App\Filament\Resources\CardholderResource\Pages;
use App\Models\Cardholder;
use App\Models\Employee;
use App\Services\GoogleCalendarApi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CardholderResource extends Resource
{
    use ManagerOnly;

    protected static ?string $model = Cardholder::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Geräte';

    protected static ?string $navigationLabel = 'Karten';

    protected static ?string $modelLabel = 'Karte';

    protected static ?string $pluralModelLabel = 'Karten';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('card_uid')
                    ->label('Karten-UID')
                    ->required()
                    ->readOnly()
                    ->maxLength(30),
                Forms\Components\Select::make('employee_id')
                    ->label('Mitarbeiter')
                    ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Karte einem Mitarbeiter zuordnen (mehrere Karten je Mitarbeiter möglich).'),
                Forms\Components\TextInput::make('username')
                    ->label('Name')
                    ->required()
                    ->maxLength(30),
                Forms\Components\Select::make('gender')
                    ->label('Geschlecht')
                    ->options(['Male' => 'Männlich', 'Female' => 'Weiblich', 'None' => 'Keine Angabe'])
                    ->default('None'),
                Forms\Components\TextInput::make('serialnumber')
                    ->label('Personalnummer')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('email')
                    ->label('E-Mail')
                    ->email()
                    ->maxLength(50),
                Forms\Components\TextInput::make('device_dep')
                    ->label('Abteilung')
                    ->helperText("Abteilung des Benutzers, oder 'All' für alle Geräte.")
                    ->maxLength(20),
                Forms\Components\Select::make('calendarId')
                    ->label('Google Kalender')
                    ->options(fn () => static::calendarOptions())
                    ->searchable()
                    ->helperText('Optional: Kalender für die automatische Eintragung der Arbeitszeit.'),
                Forms\Components\Toggle::make('add_card')
                    ->label('Registriert')
                    ->helperText('Nur registrierte Karten dürfen ein-/auschecken.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('card_uid')->label('Karten-UID')->searchable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('username')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('gender')->label('Geschlecht')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'Male' => 'Männlich', 'Female' => 'Weiblich', default => '-',
                    }),
                Tables\Columns\TextColumn::make('serialnumber')->label('Pers.-Nr.')->sortable(),
                Tables\Columns\TextColumn::make('device_dep')->label('Abteilung')->searchable(),
                Tables\Columns\TextColumn::make('user_date')->label('Erfasst')->date('d.m.Y')->sortable(),
                Tables\Columns\IconColumn::make('add_card')->label('Registriert')->boolean(),
                Tables\Columns\IconColumn::make('card_select')->label('Ausgewählt')->boolean()
                    ->trueColor('warning'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('add_card')->label('Registriert'),
                Tables\Filters\Filter::make('pending')
                    ->label('Nur neu angelernte (unregistriert)')
                    ->query(fn (Builder $query) => $query->where('add_card', 0)->where('card_select', 1)),
            ])
            ->actions([
                Tables\Actions\Action::make('register')
                    ->label('Registrieren')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Cardholder $record) => ! $record->add_card)
                    ->requiresConfirmation()
                    ->action(fn (Cardholder $record) => $record->update(['add_card' => 1, 'card_select' => 0])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Google calendar id => summary, guarded against an unconfigured / offline
     * API exactly like the legacy ManageUsers (returns [] on failure).
     */
    protected static function calendarOptions(): array
    {
        try {
            $list = GoogleCalendarApi::make()->GetCalendarsList();
        } catch (\Throwable $e) {
            return [];
        }

        return collect($list ?? [])->pluck('summary', 'id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardholders::route('/'),
            'create' => Pages\CreateCardholder::route('/create'),
            'edit' => Pages\EditCardholder::route('/{record}/edit'),
        ];
    }
}
