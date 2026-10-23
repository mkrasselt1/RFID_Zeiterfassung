<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ManagerOnly;
use App\Filament\Resources\UserLogChangeResource\Pages;
use App\Models\Employee;
use App\Models\UserLogChange;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Wer hat wann welche Stempelung angelegt, geändert oder gelöscht. Nur lesend. */
class UserLogChangeResource extends Resource
{
    use ManagerOnly;

    protected static ?string $model = UserLogChange::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Zeiterfassung';

    protected static ?string $navigationLabel = 'Änderungsprotokoll';

    protected static ?string $modelLabel = 'Änderung';

    protected static ?string $pluralModelLabel = 'Änderungen';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Wann')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('author.name')->label('Wer')->placeholder('—'),
                Tables\Columns\TextColumn::make('channel')->label('Über')->badge()
                    ->formatStateUsing(fn (string $state) => $state === UserLogChange::CHANNEL_API ? 'API' : 'Panel'),
                Tables\Columns\TextColumn::make('action')->label('Aktion')->badge()
                    ->formatStateUsing(fn (string $state) => UserLogChange::ACTIONS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        UserLogChange::ACTION_CREATED => 'success',
                        UserLogChange::ACTION_DELETED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('employee.name')->label('Mitarbeiter')->placeholder('—'),
                Tables\Columns\TextColumn::make('user_log_id')->label('Stempelung'),
                Tables\Columns\TextColumn::make('before')->label('Vorher')
                    ->state(fn (UserLogChange $record) => static::describe($record->before)),
                Tables\Columns\TextColumn::make('after')->label('Nachher')
                    ->state(fn (UserLogChange $record) => static::describe($record->after)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')->label('Mitarbeiter')
                    ->options(fn () => Employee::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('action')->label('Aktion')->options(UserLogChange::ACTIONS),
                Tables\Filters\SelectFilter::make('channel')->label('Über')
                    ->options([UserLogChange::CHANNEL_PANEL => 'Panel', UserLogChange::CHANNEL_API => 'API']),
            ]);
    }

    /** "Datum 08.06.2026 · Rein 08:00 · Raus 16:30" aus den protokollierten Feldern. */
    public static function describe(?array $values): string
    {
        if (! $values) {
            return '—';
        }

        $parts = [];
        if (isset($values['checkindate'])) {
            $parts[] = 'Datum '.date('d.m.Y', strtotime((string) $values['checkindate']));
        }
        if (isset($values['timein'])) {
            $parts[] = 'Rein '.substr((string) $values['timein'], 0, 5);
        }
        if (array_key_exists('timeout', $values)) {
            $out = (string) $values['timeout'];
            $parts[] = 'Raus '.(in_array($out, ['0', '00:00:00', ''], true) ? 'offen' : substr($out, 0, 5));
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserLogChanges::route('/'),
        ];
    }
}
