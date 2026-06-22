<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Rebuilds the work_days ledger from attendance + approved absences.
 * Example: php artisan worktime:recalc --days=60
 */
class RecalculateWorktime extends Command
{
    protected $signature = 'worktime:recalc
        {--employee= : Limit to one employee id}
        {--days=31 : Number of past days to recompute (ending today)}
        {--from= : Start date (Y-m-d); overrides --days}
        {--to= : End date (Y-m-d); defaults to today}';

    protected $description = 'Recompute the delivered-worktime ledger (work_days).';

    public function handle(WorktimeService $service): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::today();
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : $to->copy()->subDays((int) $this->option('days'));

        $employees = $this->option('employee')
            ? Employee::whereKey($this->option('employee'))->get()
            : Employee::all();

        $days = 0;
        foreach ($employees as $employee) {
            $days += $service->recalculateRange($employee, $from, $to);
        }

        $this->info(sprintf(
            'Recomputed %d employee-days (%s to %s) for %d employee(s).',
            $days, $from->toDateString(), $to->toDateString(), $employees->count(),
        ));

        return self::SUCCESS;
    }
}
