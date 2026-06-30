<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ManagerOnly;
use App\Filament\Resources\UserLogResource\Pages;
use App\Models\Cardholder;
use App\Models\Device;
use App\Models\Setting;
use App\Models\UserLog;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserLogResource extends Resource
{
    use ManagerOnly;

    protected static ?string $model = UserLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Zeiterfassung';

    protected static ?string $navigationLabel = 'Roh-Stempelungen';

    protected static ?string $modelLabel = 'Zeiteintrag';

    protected static ?string $pluralModelLabel = 'Zeiten-Aufzeichnungen';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        // Manual correction / creation of individual stampings by HR/Admin.
        return $form->schema([
            Forms\Components\Select::make('card_uid')
                ->label('Karte')
                ->options(fn () => Cardholder::query()->orderBy('username')
                    ->get()->mapWithKeys(fn (Cardholder $c) => [$c->card_uid => "{$c->username} ({$c->card_uid})"]))
                ->searchable()
                ->required()
                ->disabled(fn (string $operation) => $operation === 'edit')
                ->dehydrated()
                ->helperText('Bei Korrektur fest; beim Neuanlegen wählbar.'),
            Forms\Components\DatePicker::make('checkindate')->label('Datum')->required()->default(now()),
            Forms\Components\TimePicker::make('timein')->label('Rein (UTC)')->seconds(true)->required(),
            Forms\Components\TimePicker::make('timeout')->label('Raus (UTC)')->seconds(true)
                ->helperText('Bei vergessenem Ausstempeln hier die Zeit nachtragen und „Ausgecheckt" aktivieren.'),
            Forms\Components\Toggle::make('card_out')->label('Ausgecheckt')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = static::timezone();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('employee.name')->label('Name')
                    ->placeholder('—')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('employee.personnel_number')->label('Pers.-Nr.')->placeholder('—'),
                Tables\Columns\TextColumn::make('card_uid')->label('Karten-UID')->fontFamily('mono')->toggleable(),
                Tables\Columns\TextColumn::make('device_dep')->label('Abteilung')->searchable(),
                Tables\Columns\TextColumn::make('checkindate')->label('Datum')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('timein')->label('Rein')
                    ->formatStateUsing(fn (UserLog $record) => static::localTime($record->checkindate, $record->timein, $tz)),
                Tables\Columns\TextColumn::make('timeout')->label('Raus')
                    ->formatStateUsing(fn (UserLog $record) => $record->card_out
                        ? static::localTime($record->checkindate, $record->timeout, $tz)
                        : ''),
                Tables\Columns\TextColumn::make('elapsed')->label('Zeit')
                    ->state(fn (UserLog $record) => static::elapsed($record)),
            ])
            ->filters([
                Tables\Filters\Filter::make('range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Von'),
                        Forms\Components\DatePicker::make('until')->label('Bis'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $d) => $q->whereDate('checkindate', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('checkindate', '<=', $d));
                    }),
                Tables\Filters\SelectFilter::make('card_uid')
                    ->label('Benutzer')
                    ->options(fn () => Cardholder::query()->orderBy('username')->pluck('username', 'card_uid')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('device_dep')
                    ->label('Abteilung')
                    ->options(fn () => Device::query()->distinct()->orderBy('device_dep')->pluck('device_dep', 'device_dep')->all()),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportCsv')
                    ->label('CSV Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn ($livewire) => static::exportCsv($livewire->getFilteredTableQuery())),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(fn (UserLog $record) => app(WorktimeService::class)
                        ->recalculateForLog($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function timezone(): string
    {
        return Setting::get('timezone', 'Europe/Berlin');
    }

    /** Stored times are already in local time — show them as-is (no conversion). */
    protected static function localTime(?string $date, ?string $time, string $tz): string
    {
        if (! $time || $time === '00:00:00') {
            return '';
        }

        return substr((string) $time, 0, 8);
    }

    /** Worked duration (timeout - timein), blank while still checked in. */
    protected static function elapsed(UserLog $record): string
    {
        if (! $record->card_out || $record->timeout === '00:00:00') {
            return '';
        }
        $in = Carbon::createFromFormat('H:i:s', $record->timein);
        $out = Carbon::createFromFormat('H:i:s', $record->timeout);

        return $in->diff($out)->format('%H:%I:%S');
    }

    protected static function exportCsv(Builder $query): StreamedResponse
    {
        $tz = static::timezone();
        $filename = 'zeiten-log-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Name', 'Pers.-Nr.', 'Karten-UID', 'Abteilung', 'Datum', 'Rein', 'Raus', 'Zeit']);
            $query->with('employee')->orderBy('id', 'desc')->chunk(500, function ($rows) use ($out, $tz) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->id, $r->employee?->name ?? '', $r->employee?->personnel_number ?? '', $r->card_uid, $r->device_dep,
                        $r->checkindate,
                        static::localTime($r->checkindate, $r->timein, $tz),
                        $r->card_out ? static::localTime($r->checkindate, $r->timeout, $tz) : '',
                        static::elapsed($r),
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserLogs::route('/'),
            'create' => Pages\CreateUserLog::route('/create'),
            'edit' => Pages\EditUserLog::route('/{record}/edit'),
        ];
    }
}
