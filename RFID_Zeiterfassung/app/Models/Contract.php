<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A work contract with a validity range and a pluggable expected-worktime model.
 */
class Contract extends Model
{
    public const MODEL_WEEKLY = 'weekly_hours';
    public const MODEL_MONTHLY = 'monthly_hours';
    public const MODEL_DAILY = 'daily_hours';
    public const MODEL_TRACKING = 'tracking_only';

    public const MODELS = [
        self::MODEL_WEEKLY => 'Stunden pro Woche',
        self::MODEL_MONTHLY => 'Stunden pro Monat',
        self::MODEL_DAILY => 'Stunden pro Arbeitstag',
        self::MODEL_TRACKING => 'Nur Erfassung (keine Sollzeit)',
    ];

    protected $fillable = [
        'employee_id', 'title', 'valid_from', 'valid_to',
        'worktime_model', 'target_hours', 'workdays', 'vacation_days_per_year',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'target_hours' => 'decimal:2',
        'workdays' => 'array',
        'vacation_days_per_year' => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** ISO weekday numbers (Mon=1..Sun=7) that count as working days. */
    public function workdayList(): array
    {
        return ! empty($this->workdays) ? $this->workdays : [1, 2, 3, 4, 5];
    }

    public function isWorkday(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), $this->workdayList(), true);
    }

    /**
     * Expected working minutes on a given date for this contract's model.
     * Non-workdays and tracking-only contracts yield 0.
     */
    public function expectedMinutesForDate(Carbon $date): int
    {
        if ($this->worktime_model === self::MODEL_TRACKING) {
            return 0;
        }
        // Non-workdays and public holidays carry no expected time (paid day off).
        if (! $this->isWorkday($date) || Holiday::isHoliday($date)) {
            return 0;
        }

        $hours = (float) $this->target_hours;
        $workdaysPerWeek = max(count($this->workdayList()), 1);

        return match ($this->worktime_model) {
            self::MODEL_DAILY => (int) round($hours * 60),
            self::MODEL_WEEKLY => (int) round($hours * 60 / $workdaysPerWeek),
            self::MODEL_MONTHLY => (int) round($hours * 60 / max($this->workdaysInMonth($date), 1)),
            default => 0,
        };
    }

    /** Workdays in the month, excluding public holidays (so the monthly target
     *  is distributed only over days actually worked). */
    private function workdaysInMonth(Carbon $date): int
    {
        $list = $this->workdayList();
        $count = 0;
        $cursor = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();
        while ($cursor->lte($end)) {
            if (in_array($cursor->isoWeekday(), $list, true) && ! Holiday::isHoliday($cursor)) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }
}
