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

    /** Statutory minimum breaks (ArbZG §4): >6 h → 30 min, >9 h → 45 min. */
    public const DEFAULT_BREAK_RULES = [
        ['from_minutes' => 360, 'minutes' => 30],
        ['from_minutes' => 540, 'minutes' => 45],
    ];

    /** Daily deviations below this many minutes don't accumulate. */
    public const DEFAULT_BALANCE_TOLERANCE = 5;

    protected $fillable = [
        'employee_id', 'title', 'valid_from', 'valid_to',
        'worktime_model', 'target_hours', 'workdays', 'vacation_days_per_year',
        'break_rules', 'balance_tolerance_minutes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'target_hours' => 'decimal:2',
        'workdays' => 'array',
        'vacation_days_per_year' => 'decimal:1',
        'break_rules' => 'array',
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
     * The break staircase that applies to this contract: its own override, or
     * the global default from the settings. Entries are normalized to
     * ['from_minutes' => int, 'minutes' => int] and sorted ascending.
     */
    public function breakRules(): array
    {
        return static::normalizeBreakRules($this->break_rules ?: null)
            ?? static::globalBreakRules();
    }

    /** The app-wide default staircase (settings, falling back to ArbZG). */
    public static function globalBreakRules(): array
    {
        return static::normalizeBreakRules(Setting::get('break_rules'))
            ?? self::DEFAULT_BREAK_RULES;
    }

    /** Null for an empty/unusable staircase, so callers can fall back. */
    public static function normalizeBreakRules(mixed $rules): ?array
    {
        if (! is_array($rules)) {
            return null;
        }

        $clean = [];
        foreach ($rules as $rule) {
            $from = (int) ($rule['from_minutes'] ?? 0);
            $minutes = (int) ($rule['minutes'] ?? 0);
            if ($from >= 0 && $minutes > 0) {
                $clean[] = ['from_minutes' => $from, 'minutes' => $minutes];
            }
        }
        usort($clean, fn (array $a, array $b) => $a['from_minutes'] <=> $b['from_minutes']);

        return $clean ?: null;
    }

    /** Tolerance in minutes for this contract: its own override, or the global one. */
    public function balanceTolerance(): int
    {
        return $this->balance_tolerance_minutes !== null
            ? max(0, (int) $this->balance_tolerance_minutes)
            : static::globalBalanceTolerance();
    }

    /** The app-wide default tolerance (settings, falling back to 5 min; 0 = off). */
    public static function globalBalanceTolerance(): int
    {
        $value = Setting::get('balance_tolerance_minutes');

        return $value === null ? self::DEFAULT_BALANCE_TOLERANCE : max(0, (int) $value);
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
