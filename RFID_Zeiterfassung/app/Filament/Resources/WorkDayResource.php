<?php

namespace App\Filament\Resources;

use App\Filament\Pages\WorktimeReportPage;
use App\Filament\Resources\WorkDayResource\Pages;
use App\Models\Employee;
use App\Models\WorkDay;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkDayResource extends Resource
{
    protected static ?string $model = WorkDay::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Zeiterfassung';

    protected static ?string $navigationLabel = 'Arbeitszeitkonto';

    protected static ?string $modelLabel = 'Monatsbilanz';

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
        $isManager = fn () => auth()->user()?->canManagePeople() ?? false;

        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label('Monat')->sortable()
                    ->formatStateUsing(fn (?string $state) => $state
                        ? Carbon::createFromFormat('Y-m', $state)->translatedFormat('F Y') : ''),
                Tables\Columns\TextColumn::make('employee.name')->label('Mitarbeiter')
                    ->visible($isManager),
                Tables\Columns\TextColumn::make('worked_minutes')->label('Ist')
                    ->formatStateUsing(fn (int $state) => static::hhmm($state))
                    ->summarize(Sum::make()->formatStateUsing(fn ($state) => static::hhmm((int) $state))),
                Tables\Columns\TextColumn::make('expected_minutes')->label('Soll')
                    ->formatStateUsing(fn (int $state) => static::hhmm($state))
                    ->summarize(Sum::make()->formatStateUsing(fn ($state) => static::hhmm((int) $state))),
                Tables\Columns\TextColumn::make('balance_minutes')->label('Saldo')
                    ->formatStateUsing(fn (int $state) => static::hhmm($state))
                    ->color(fn (int $state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                    ->weight('bold')
                    ->summarize(Sum::make()->formatStateUsing(fn ($state) => static::hhmm((int) $state))),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')->label('Mitarbeiter')
                    ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->visible($isManager),
                Tables\Filters\SelectFilter::make('year')
                    ->label('Jahr')
                    ->options(fn () => collect(range((int) now()->year, (int) now()->year - 5))
                        ->mapWithKeys(fn ($y) => [$y => (string) $y])->all())
                    ->default((int) now()->year)
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['value'], fn (Builder $q, $y) => $q->whereYear('work_date', $y))),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Details')
                    ->icon('heroicon-o-magnifying-glass')
                    ->url(fn (WorkDay $record) => WorktimeReportPage::getUrl()
                        .'?employee='.$record->employee_id.'&period='.$record->period),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportCsv')
                    ->label('CSV Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn ($livewire) => static::exportCsv($livewire->getFilteredTableQuery())),
                Tables\Actions\Action::make('recalc')
                    ->label('Neu berechnen')
                    ->icon('heroicon-o-arrow-path')
                    ->visible($isManager)
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

    /** Monthly aggregate per employee: one row per (employee, month). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->selectRaw('MIN(id) as id, employee_id, substr(work_date, 1, 7) as period, '
                .'SUM(worked_minutes) as worked_minutes, '
                .'SUM(expected_minutes) as expected_minutes, '
                .'SUM(balance_minutes) as balance_minutes')
            ->groupByRaw('employee_id, substr(work_date, 1, 7)');

        if ($start = \App\Models\Setting::get('tracking_start')) {
            $query->where('work_date', '>=', $start);
        }

        $user = auth()->user();
        if ($user && ! $user->canManagePeople()) {
            $query->where('employee_id', $user->id);
        }

        return $query;
    }

    /** CSV of the current (filtered) monthly view. */
    protected static function exportCsv(Builder $query): StreamedResponse
    {
        $filename = 'arbeitszeitkonto-'.now()->format('Y-m-d_His').'.csv';
        $rows = $query->with('employee')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Monat', 'Mitarbeiter', 'Ist', 'Soll', 'Saldo']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->period,
                    $r->employee?->name,
                    static::hhmm((int) $r->worked_minutes),
                    static::hhmm((int) $r->expected_minutes),
                    static::hhmm((int) $r->balance_minutes),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
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
