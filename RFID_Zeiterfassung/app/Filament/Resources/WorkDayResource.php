<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WorkDayResource\Pages;
use App\Models\Absence;
use App\Models\Employee;
use App\Models\WorkDay;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkDayResource extends Resource
{
    protected static ?string $model = WorkDay::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Zeiterfassung';

    protected static ?string $navigationLabel = 'Arbeitszeitkonto';

    protected static ?string $modelLabel = 'Tagesbilanz';

    protected static ?string $pluralModelLabel = 'Arbeitszeitkonto';

    protected static ?int $navigationSort = 3;

    /** Format signed minutes as e.g. "8:00" / "-1:30". */
    public static function hhmm(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->defaultGroup('work_date')
            ->groups([
                Group::make('work_date')
                    ->label('Monat')
                    ->getKeyFromRecordUsing(fn (WorkDay $r) => substr((string) $r->work_date, 0, 7))
                    ->getTitleFromRecordUsing(fn (WorkDay $r) => Carbon::parse($r->work_date)->translatedFormat('F Y'))
                    ->orderQueryUsing(fn (Builder $q, string $direction) => $q->orderBy('work_date', $direction)),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('employee.name')->label('Mitarbeiter')
                    ->searchable()->sortable()
                    ->visible(fn () => auth()->user()?->canManagePeople() ?? false),
                Tables\Columns\TextColumn::make('work_date')->label('Datum')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('worked_minutes')->label('Ist')
                    ->formatStateUsing(fn (int $state) => static::hhmm($state))
                    ->summarize(Sum::make()->label('Ist')->formatStateUsing(fn ($state) => static::hhmm((int) $state))),
                Tables\Columns\TextColumn::make('expected_minutes')->label('Soll')
                    ->formatStateUsing(fn (int $state) => static::hhmm($state))
                    ->summarize(Sum::make()->label('Soll')->formatStateUsing(fn ($state) => static::hhmm((int) $state))),
                Tables\Columns\TextColumn::make('balance_minutes')->label('Saldo')
                    ->formatStateUsing(fn (int $state) => static::hhmm($state))
                    ->color(fn (int $state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Saldo')->formatStateUsing(fn ($state) => static::hhmm((int) $state))),
                Tables\Columns\TextColumn::make('absence.type')->label('Abwesenheit')
                    ->formatStateUsing(fn (?string $state) => $state ? (Absence::TYPES[$state] ?? $state) : '')
                    ->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')->label('Mitarbeiter')
                    ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn () => auth()->user()?->canManagePeople() ?? false),
                Tables\Filters\Filter::make('range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Von'),
                        Forms\Components\DatePicker::make('until')->label('Bis'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'], fn (Builder $q, $d) => $q->whereDate('work_date', '>=', $d))
                        ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('work_date', '<=', $d))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportCsv')
                    ->label('CSV Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn ($livewire) => static::exportCsv($livewire->getFilteredTableQuery())),
                Tables\Actions\Action::make('recalc')
                    ->label('Neu berechnen')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn () => auth()->user()?->canManagePeople() ?? false)
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Von')->default(now()->startOfMonth())->required(),
                        Forms\Components\DatePicker::make('to')->label('Bis')->default(now())->required(),
                    ])
                    ->action(function (array $data) {
                        $service = app(WorktimeService::class);
                        $from = Carbon::parse($data['from']);
                        $to = Carbon::parse($data['to']);
                        Employee::all()->each(fn (Employee $e) => $service->recalculateRange($e, $from, $to));
                        Notification::make()->title('Arbeitszeitkonto neu berechnet')->success()->send();
                    }),
            ]);
    }

    /** CSV of the current (filtered) ledger view, per day with a month column. */
    protected static function exportCsv(Builder $query): StreamedResponse
    {
        $filename = 'arbeitszeitkonto-'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Monat', 'Datum', 'Mitarbeiter', 'Ist', 'Soll', 'Saldo', 'Abwesenheit']);
            $query->with('employee', 'absence')->orderBy('work_date')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        substr((string) $r->work_date, 0, 7),
                        $r->work_date,
                        $r->employee?->name,
                        static::hhmm((int) $r->worked_minutes),
                        static::hhmm((int) $r->expected_minutes),
                        static::hhmm((int) $r->balance_minutes),
                        $r->absence ? (Absence::TYPES[$r->absence->type] ?? $r->absence->type) : '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkDays::route('/'),
        ];
    }
}
