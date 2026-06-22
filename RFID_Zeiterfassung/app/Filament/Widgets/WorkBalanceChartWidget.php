<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Models\Setting;
use App\Models\WorkDay;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Soll vs. Ist (hours) per month over the last 12 months for the selected
 * employee (dashboard filter for managers, else self).
 */
class WorkBalanceChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Soll / Ist je Monat';

    protected static ?int $sort = 3;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $employee = (! $user || ! $user->canManagePeople())
            ? $user
            : (Employee::find($this->filters['employee_id'] ?? null) ?? $user);
        if (! $employee) {
            return ['datasets' => [], 'labels' => []];
        }
        $goLive = Setting::get('tracking_start');

        $labels = [];
        $soll = [];
        $ist = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $q = WorkDay::where('employee_id', $employee->id)
                ->when($goLive, fn ($w) => $w->where('work_date', '>=', $goLive))
                ->whereBetween('work_date', [$cursor->copy()->startOfMonth()->toDateString(), $cursor->copy()->endOfMonth()->toDateString()]);
            $row = $q->selectRaw('SUM(expected_minutes) e, SUM(worked_minutes) w')->first();
            $labels[] = $cursor->translatedFormat('M y');
            $soll[] = round(((int) ($row->e ?? 0)) / 60, 1);
            $ist[] = round(((int) ($row->w ?? 0)) / 60, 1);
            $cursor->addMonth();
        }

        return [
            'datasets' => [
                ['label' => 'Soll (h)', 'data' => $soll, 'backgroundColor' => 'rgba(148, 163, 184, 0.7)'],
                ['label' => 'Ist (h)', 'data' => $ist, 'backgroundColor' => 'rgba(16, 185, 129, 0.7)'],
            ],
            'labels' => $labels,
        ];
    }
}
