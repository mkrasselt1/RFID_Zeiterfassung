<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ManagerOnly;
use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeviceResource extends Resource
{
    use ManagerOnly;

    protected static ?string $model = Device::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Geräte';

    protected static ?string $navigationLabel = 'Leser';

    protected static ?string $modelLabel = 'Leser';

    protected static ?string $pluralModelLabel = 'Leser';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('device_name')
                    ->label('Name')
                    ->required()
                    ->maxLength(50),
                Forms\Components\TextInput::make('device_dep')
                    ->label('Abteilung')
                    ->required()
                    ->maxLength(20),
                Forms\Components\Select::make('device_mode')
                    ->label('Modus')
                    ->options([
                        Device::MODE_LEARN => 'Registrierung (neue Karten anlernen)',
                        Device::MODE_TIME => 'Zeiterfassung (Ein-/Auschecken)',
                    ])
                    ->default(Device::MODE_LEARN)
                    ->required(),
                Forms\Components\TextInput::make('device_uid')
                    ->label('Token')
                    ->helperText('16-stelliger Hex-Token, den das Gerät als device_token sendet. Wird automatisch erzeugt.')
                    ->default(fn () => bin2hex(random_bytes(8)))
                    ->required()
                    ->readOnly()
                    ->maxLength(16),
                Forms\Components\DatePicker::make('device_date')
                    ->label('Datum')
                    ->default(now())
                    ->required(),
                Forms\Components\TextInput::make('local_ip')
                    ->label('Adresse im Netz')
                    ->placeholder('z. B. 192.168.1.50')
                    ->helperText('Unter dieser Adresse ist die Konfigurationsseite des Lesers '
                        .'erreichbar. Meldet das Gerät selbst, lässt sich aber überschreiben.')
                    ->maxLength(45),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('device_name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('device_dep')->label('Abteilung')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('device_uid')->label('Token')->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('device_date')->label('Datum')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('device_mode')
                    ->label('Modus')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === Device::MODE_TIME ? 'Zeiterfassung' : 'Registrierung')
                    ->color(fn (int $state): string => $state === Device::MODE_TIME ? 'success' : 'warning'),
                // Ein stummer Leser soll auffallen, bevor jemand seine Zeiten vermisst.
                Tables\Columns\TextColumn::make('last_seen_at')->label('Zuletzt gesehen')
                    ->dateTime('d.m.Y H:i')->placeholder('nie')->sortable()
                    ->description(fn (Device $r) => $r->last_seen_at?->diffForHumans())
                    ->color(fn (Device $r) => $r->isSilent() ? 'danger' : null),
                Tables\Columns\TextColumn::make('pending_count')->label('Im Puffer')
                    ->formatStateUsing(fn (?int $state) => (int) $state === 0 ? '–' : $state)
                    ->color(fn (?int $state) => (int) $state > 0 ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('firmware_version')->label('Firmware')
                    ->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('local_ip')->label('Adresse')
                    ->placeholder('–')->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                // Öffnet die Konfigurationsseite auf dem Leser selbst — WLAN,
                // Zeitserver, Serveradresse. Bewusst ein Link und kein Formular
                // aus dem Panel heraus: läuft das Panel über HTTPS, blockiert
                // der Browser abgeschickte Formulare an eine http-Adresse im
                // lokalen Netz ("mixed content"), ein Link in einem neuen Tab
                // dagegen funktioniert. Das Token steht daneben zum Kopieren.
                Tables\Actions\Action::make('configure')
                    ->label('Konfigurieren')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('gray')
                    ->url(fn (Device $record) => $record->configUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (Device $record) => $record->configUrl() !== null)
                    ->tooltip('Öffnet die Konfigurationsseite des Lesers im lokalen Netz. '
                        .'Anmeldung mit dem Token des Geräts.'),
                Tables\Actions\Action::make('newToken')
                    ->label('Neuer Token')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(fn (Device $record) => $record->update(['device_uid' => bin2hex(random_bytes(8))])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
