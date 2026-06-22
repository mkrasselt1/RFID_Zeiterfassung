<?php

namespace App\Filament\Widgets;

use App\Models\Setting;
use App\Models\WorkDay;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/**
 * Cumulative overtime balance (hours) over the last 12 months for the logged-in
 * employee, starting from the carried-over balance before the window.
 */
class OvertimeTrendWidget extends ChartWidget
{
    protected static ?string $heading = 'Überstunden-Verlauf';

    protected static ?int $sort = 2;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $employee = auth()->user();
        $goLive = Setting::get('tracking_start');
        $base = fn () => WorkDay::where('employee_id', $employee->id)
            ->when($goLive, fn ($q) => $q->where('work_date', '>=', $goLive));

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11);
        $cumulative = (int) $base()->where('work_date', '<', $windowStart->toDateString())->sum('balance_minutes');

        $labels = [];
        $values = [];
        $cursor = $windowStart->copy();
        for ($i = 0; $i < 12; $i++) {
            $cumulative += (int) $base()
                ->whereBetween('work_date', [$cursor->copy()->startOfMonth()->toDateString(), $cursor->copy()->endOfMonth()->toDateString()])
                ->sum('balance_minutes');
            $labels[] = $cursor->translatedFormat('M y');
            $values[] = round($cumulative / 60, 1);
            $cursor->addMonth();
        }

        return [
            'datasets' => [[
                'label' => 'Saldo (h)',
                'data' => $values,
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.2)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $labels,
        ];
    }
}
