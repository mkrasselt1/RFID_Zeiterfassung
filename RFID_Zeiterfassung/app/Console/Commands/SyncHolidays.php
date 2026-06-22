<?php

namespace App\Console\Commands;

use App\Services\HolidayService;
use Illuminate\Console\Command;

/**
 * Imports German public holidays for a year into the holidays table.
 * Example: php artisan holidays:sync --year=2026
 */
class SyncHolidays extends Command
{
    protected $signature = 'holidays:sync
        {--year= : Year to import (defaults to current year)}
        {--region= : DE region code, e.g. DE-SN (defaults to the configured one)}';

    protected $description = 'Import public holidays for a year (per Bundesland).';

    public function handle(HolidayService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $count = $service->sync($year, $this->option('region'));

        $this->info("Imported {$count} holidays for {$year} (".HolidayService::region().').');

        return self::SUCCESS;
    }
}
