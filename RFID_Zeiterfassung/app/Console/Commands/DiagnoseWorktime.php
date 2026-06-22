<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\UserLog;
use App\Models\WorkDay;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Diagnoses why a given employee's Ist/Soll is empty for a month: shows cards,
 * the active contract + validity, stampings, and the computed per-day values.
 * Example: php artisan worktime:diagnose 3 2026-06
 */
class DiagnoseWorktime extends Command
{
    protected $signature = 'worktime:diagnose {employee : Employee id} {month? : YYYY-MM (default current)}';

    protected $description = 'Show why an employee has no Ist/Soll in a month.';

    public function handle(WorktimeService $service): int
    {
        $employee = Employee::find($this->argument('employee'));
        if (! $employee) {
            $this->error('Mitarbeiter nicht gefunden.');

            return self::FAILURE;
        }

        $month = $this->argument('month') ?: now()->format('Y-m');
        [$y, $m] = array_map('intval', explode('-', $month));
        $start = Carbon::create($y, $m, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $this->info("Mitarbeiter: {$employee->name} (#{$employee->id}, Rolle {$employee->role})");

        $uids = $employee->cards()->pluck('card_uid');
        $this->line('Karten: '.$uids->count().' ['.$uids->implode(', ').']');

        $c = $employee->activeContractOn($start->copy()->addDays(14));
        $this->line('Vertrag (Monatsmitte): '.($c
            ? "{$c->worktime_model}, Soll {$c->target_hours}, Arbeitstage [".implode(',', $c->workdayList()).'], '
              .'gültig '.$c->valid_from->toDateString().'..'.($c->valid_to?->toDateString() ?? 'offen')
            : 'KEINER  ← dann ist Soll immer 0'));

        $logs = UserLog::whereIn('card_uid', $uids)
            ->whereBetween('checkindate', [$start->toDateString(), $end->toDateString()])->get();
        $this->line('Stempelungen im Monat: '.$logs->count()
            .' (abgeschlossen '.$logs->where('card_out', 1)->count()
            .', offen/ohne Auschecken '.$logs->where('card_out', 0)->count().')');

        $service->recalculateRange($employee, $start, $end);
        $rows = WorkDay::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q->where('worked_minutes', '>', 0)->orWhere('expected_minutes', '>', 0))
            ->orderBy('work_date')->get();

        if ($rows->isEmpty()) {
            $this->warn('Keine Tage mit Ist oder Soll > 0 in diesem Monat.');
        }
        foreach ($rows as $r) {
            $this->line("  {$r->work_date}: Ist {$r->worked_minutes}m  Soll {$r->expected_minutes}m  Saldo {$r->balance_minutes}m");
        }
        $this->info('Summe Monat: Ist '.$rows->sum('worked_minutes').'m  Soll '.$rows->sum('expected_minutes').'m');

        return self::SUCCESS;
    }
}
