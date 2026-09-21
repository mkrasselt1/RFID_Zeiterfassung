<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WorkDayResource;
use App\Models\Absence;
use App\Models\Employee;
use App\Services\BalanceFormat;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Time stats for the selected employee (managers can pick one via the dashboard
 * filter; employees always see their own): vacation balance, overtime balance
 * and this week's worked-vs-expected, plus pending requests for managers.
 */
class MyStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    /** Employee whose data to show: dashboard-selected for managers, else self. */
    protected function targetEmployee(): ?Employee
    {
        $user = auth()->user();
        if (! $user || ! $user->canManagePeople()) {
            return $user;
        }

        return Employee::find($this->filters['employee_id'] ?? null) ?? $user;
    }

    /**
     * Urlaub als "Rest von Anspruch" — die nackte Restzahl allein war nicht zu
     * deuten, und ohne gepflegten Anspruch gab sie die genommenen Tage als
     * Minus aus, was wie ein negativer Resturlaub aussah.
     */
    protected static function vacationStat(Employee $employee, int $year): Stat
    {
        $entitlement = $employee->vacationEntitlement($year);
        $taken = $employee->vacationTaken($year);

        if ($entitlement === null) {
            return Stat::make('Urlaub '.$year, Absence::formatDays($taken).' Tage genommen')
                ->description('Kein Urlaubsanspruch im Vertrag hinterlegt')
                ->color('warning');
        }

        return Stat::make('Resturlaub '.$year, Absence::formatDays($entitlement - $taken)
            .' von '.Absence::formatDays($entitlement).' Tagen')
            ->description(Absence::formatDays($taken).' Tage genommen');
    }

    protected function getStats(): array
    {
        $employee = $this->targetEmployee();
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
            static::vacationStat($employee, now()->year),
            Stat::make('Überstunden-Saldo', BalanceFormat::make($employee->overtimeBalanceMinutes())),
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
