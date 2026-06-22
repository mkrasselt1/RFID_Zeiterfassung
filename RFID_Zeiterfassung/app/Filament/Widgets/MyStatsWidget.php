<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WorkDayResource;
use App\Models\Absence;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Personal time stats for the logged-in employee: vacation balance, overtime
 * balance and this week's worked-vs-expected. Managers additionally see the
 * number of pending absence requests awaiting their decision.
 */
class MyStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $employee = auth()->user();
        if (! $employee) {
            return [];
        }

        $service = app(WorktimeService::class);
        $startOfWeek = Carbon::now()->startOfWeek();
        $service->recalculateRange($employee, $startOfWeek, Carbon::now());

        $weekWorked = (int) $employee->workDays()
            ->whereDate('work_date', '>=', $startOfWeek->toDateString())
            ->sum('worked_minutes');
        $weekExpected = (int) $employee->workDays()
            ->whereDate('work_date', '>=', $startOfWeek->toDateString())
            ->sum('expected_minutes');

        $stats = [
            Stat::make('Resturlaub ' . now()->year, number_format($employee->vacationBalance(now()->year), 1) . ' Tage'),
            Stat::make('Überstunden-Saldo', WorkDayResource::hhmm($employee->overtimeBalanceMinutes()) . ' h'),
            Stat::make('Diese Woche', WorkDayResource::hhmm($weekWorked) . ' / ' . WorkDayResource::hhmm($weekExpected) . ' h')
                ->description('Ist / Soll'),
        ];

        if ($employee->canManagePeople()) {
            $pending = Absence::where('status', Absence::STATUS_PENDING)->count();
            $stats[] = Stat::make('Offene Anträge', (string) $pending)
                ->description('Zu genehmigen')
                ->color($pending > 0 ? 'warning' : 'gray');
        }

        return $stats;
    }
}
