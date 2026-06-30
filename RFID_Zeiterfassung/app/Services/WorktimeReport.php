<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\UserLog;
use App\Models\WorkDay;
use Carbon\Carbon;

/**
 * Builds the per-employee monthly worktime report (Arbeitszeitnachweis).
 * Displays whole weeks as 7-day blocks (Mon–Sun) — including days from the
 * adjacent months so every week is complete — with weekly subtotals and a
 * month summary. Spillover days are flagged (`in_month=false`) and excluded
 * from the month totals. Shared by the panel page and the PDF export.
 */
class WorktimeReport
{
    private static array $weekdays = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    public function __construct(private WorktimeService $worktime)
    {
    }

    public function forMonth(Employee $employee, int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $displayStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $displayEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        // Ensure the ledger is current for everything we display.
        $this->worktime->recalculateRange($employee, $displayStart, $displayEnd);

        $tz = Setting::get('timezone', 'Europe/Berlin');
        $cardUids = $employee->cards()->pluck('card_uid')->all();

        $ledger = WorkDay::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$displayStart->toDateString(), $displayEnd->toDateString()])
            ->with('absence')
            ->get()->keyBy(fn (WorkDay $w) => substr((string) $w->work_date, 0, 10));

        $logsByDate = UserLog::whereIn('card_uid', $cardUids)
            ->whereBetween('checkindate', [$displayStart->toDateString(), $displayEnd->toDateString()])
            ->get()->groupBy(fn (UserLog $l) => substr((string) $l->checkindate, 0, 10));

        $weeks = [];
        $monthSum = ['ist' => 0, 'soll' => 0, 'saldo' => 0];
        $absenceDays = [];

        for ($cursor = $displayStart->copy(); $cursor->lte($displayEnd); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $inMonth = $cursor->between($monthStart, $monthEnd);
            $wd = $ledger->get($key);
            $logs = $logsByDate->get($key);

            $ist = (int) ($wd->worked_minutes ?? 0);
            $soll = (int) ($wd->expected_minutes ?? 0);
            $saldo = (int) ($wd->balance_minutes ?? 0);

            $hint = '';
            if ($wd?->absence) {
                $hint = Absence::TYPES[$wd->absence->type] ?? $wd->absence->type;
            } elseif (Holiday::isHoliday($cursor)) {
                $hint = Holiday::map()[$cursor->format('Y-m-d')] ?? 'Feiertag';
            } elseif ($cursor->isWeekend()) {
                $hint = 'Wochenende';
            }

            $row = [
                'date' => $key,
                'day' => $cursor->format('d.'),
                'wd' => self::$weekdays[$cursor->isoWeekday()],
                'in' => $logs ? $this->localTime($key, $logs->min('timein'), $tz) : '',
                'out' => $logs ? $this->localTime($key, $logs->where('card_out', 1)->max('timeout'), $tz) : '',
                'multiple' => $logs && $logs->count() > 1,
                'ist' => $ist,
                'soll' => $soll,
                'saldo' => $saldo,
                'hint' => $hint,
                'in_month' => $inMonth,
                'weekend' => $cursor->isWeekend(),
            ];

            $weekKey = $cursor->isoFormat('GGGG-WW');
            $weeks[$weekKey]['kw'] ??= (int) $cursor->isoWeek();
            $weeks[$weekKey]['rows'][] = $row;
            $weeks[$weekKey]['sum']['ist'] = ($weeks[$weekKey]['sum']['ist'] ?? 0) + $ist;
            $weeks[$weekKey]['sum']['soll'] = ($weeks[$weekKey]['sum']['soll'] ?? 0) + $soll;
            $weeks[$weekKey]['sum']['saldo'] = ($weeks[$weekKey]['sum']['saldo'] ?? 0) + $saldo;

            if ($inMonth) {
                $monthSum['ist'] += $ist;
                $monthSum['soll'] += $soll;
                $monthSum['saldo'] += $saldo;
                if ($wd?->absence) {
                    $absenceDays[$wd->absence->type] = ($absenceDays[$wd->absence->type] ?? 0) + 1;
                }
            }
        }

        // Year view: carryover from previous years + this year's running balance.
        $goLive = Setting::get('tracking_start');
        $base = fn () => WorkDay::where('employee_id', $employee->id)
            ->when($goLive, fn ($q) => $q->where('work_date', '>=', $goLive));
        $carryover = (int) $base()->where('work_date', '<', "{$year}-01-01")->sum('balance_minutes');
        $yearBalance = (int) $base()
            ->whereBetween('work_date', ["{$year}-01-01", "{$year}-12-31"])->sum('balance_minutes');

        return [
            'employee' => $employee,
            'contract' => $employee->activeContractOn($monthStart),
            'period' => $monthStart,
            'weeks' => array_values($weeks),
            'month_sum' => $monthSum,
            'carryover' => $carryover,
            'year_balance' => $yearBalance,
            'total_balance' => $carryover + $yearBalance,
            'vacation_left' => $employee->vacationBalance($year),
            'special_taken' => $employee->specialLeaveTaken($year),
            'absence_days' => $absenceDays,
        ];
    }

    /** Stored times are already in local time — show them as-is (HH:MM). */
    private function localTime(?string $date, ?string $time, string $tz): string
    {
        if (! $time || $time === '00:00:00') {
            return '';
        }

        return substr((string) $time, 0, 5);
    }

    /** Format signed minutes as "8:00" / "-1:30". */
    public static function hhmm(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }
}
