<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\UserLog;
use App\Models\WorkDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Rolls up delivered worktime: reads attendance from `users_logs` and approved
 * absences, compares against the employee's active contract, and upserts the
 * per-day `work_days` ledger. Intentionally NOT called from the device API, so
 * that endpoint's latency/response are unchanged.
 *
 * Balance rules per day:
 *   no absence            -> balance = worked - expected
 *   vacation / special / sick -> credited the expected hours -> balance = worked
 *   unpaid                -> not owed -> expected 0, balance = worked
 *   overtime_reduction    -> drawn from overtime -> balance = worked - expected
 *
 * Break rules: the contract's staircase (or the global default) sets a minimum
 * break per attendance length. Time the employee already stamped out for counts
 * towards it, so only the missing remainder is deducted.
 */
class WorktimeService
{
    /** Net worked minutes: stamped attendance minus the automatic break. */
    public function workedMinutes(Employee $employee, CarbonInterface $date): int
    {
        return $this->dayMinutes($employee, $date)['net'];
    }

    /**
     * Break breakdown for one day: `gross` (stamped attendance), `stamped_break`
     * (gaps between stampings), `break` (the automatic deduction on top) and
     * `net` (gross - break). Attribution follows the `employee_id` stamped on
     * each record at check-in, so a later chip reassignment never moves
     * historical time between accounts.
     *
     * @return array{gross:int, stamped_break:int, break:int, net:int}
     */
    public function dayMinutes(Employee $employee, CarbonInterface $date): array
    {
        $logs = UserLog::where('employee_id', $employee->id)
            ->where('checkindate', $date->toDateString())
            ->get();

        $spans = [];
        $gross = 0;
        foreach ($logs as $log) {
            $minutes = $log->card_out
                ? $this->logMinutes($log)
                : $this->runningMinutes($log, $date);
            if ($minutes <= 0) {
                continue;
            }
            $start = $this->minutesOfDay($log->timein);
            $spans[] = [$start, $start + $minutes];
            $gross += $minutes;
        }

        $stamped = $this->gapMinutes($spans);
        $rules = $employee->activeContractOn(Carbon::parse($date->toDateString()))?->breakRules()
            ?? Contract::globalBreakRules();
        $deduction = $this->breakDeduction($gross, $stamped, $rules);

        return [
            'gross' => $gross,
            'stamped_break' => $stamped,
            'break' => $deduction,
            'net' => $gross - $deduction,
        ];
    }

    /** Minutes the employee was stamped out between two stampings of the day. */
    private function gapMinutes(array $spans): int
    {
        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $gaps = 0;
        $previousEnd = null;
        foreach ($spans as [$start, $end]) {
            if ($previousEnd !== null && $start > $previousEnd) {
                $gaps += $start - $previousEnd;
            }
            $previousEnd = max($previousEnd ?? $end, $end);
        }

        return $gaps;
    }

    /**
     * Minutes to deduct on top of the break the employee already stamped.
     *
     * `$gross` is the stamped presence and already excludes the gaps, so the
     * staircase is matched against it directly. The deduction is capped so a day
     * can never be pushed below the threshold that triggered it — 6:15 presence
     * becomes 6:00, not 5:45.
     */
    public function breakDeduction(int $gross, int $stampedBreak, array $rules): int
    {
        $required = 0;
        $threshold = 0;
        foreach ($rules as $rule) {
            if ($gross > $rule['from_minutes']) {
                $required = $rule['minutes'];
                $threshold = $rule['from_minutes'];
            }
        }

        $deduction = max(0, $required - $stampedBreak);

        return min($deduction, max(0, $gross - $threshold));
    }

    /**
     * A day's balance after the tolerance.
     *
     * The tolerance is a threshold, not a deduction: a deviation below it does
     * not accumulate at all (a 4-minute day counts as 0), while at or above it
     * the *full* balance counts (a 5-minute day counts as 5, not as 0). So it
     * suppresses stamping noise without ever quietly shaving real time.
     * A tolerance of 0 disables it.
     */
    public function applyTolerance(int $balance, int $tolerance): int
    {
        return abs($balance) < $tolerance ? 0 : $balance;
    }

    /** Wall-clock minutes since midnight for a stored 'H:i:s' time. */
    private function minutesOfDay(?string $time): int
    {
        $parts = explode(':', (string) $time);

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    /**
     * An open stamping (no checkout yet) counts up to *now*, so the running day
     * shows the time already delivered instead of nothing.
     *
     * Only on the day it belongs to: a forgotten checkout on a past day still
     * counts as zero rather than silently growing to 24 hours. `now` comes from
     * the same clock that wrote `timein` (Carbon, app timezone), so the
     * difference is correct regardless of which zone that is.
     */
    private function runningMinutes(UserLog $log, CarbonInterface $date): int
    {
        if (empty($log->timein) || ! Carbon::parse($date->toDateString())->isToday()) {
            return 0;
        }

        $now = Carbon::now();

        return max(0, ($now->hour * 60 + $now->minute) - $this->minutesOfDay($log->timein));
    }

    private function logMinutes(UserLog $log): int
    {
        if (empty($log->timein) || empty($log->timeout) || $log->timeout === '00:00:00') {
            return 0;
        }
        $in = Carbon::createFromFormat('H:i:s', $log->timein);
        $out = Carbon::createFromFormat('H:i:s', $log->timeout);
        $diff = $in->diffInMinutes($out, false);
        if ($diff < 0) {
            $diff += 24 * 60; // crossed midnight (checkout next morning)
        }

        return (int) $diff;
    }

    public function expectedMinutes(Employee $employee, CarbonInterface $date): int
    {
        $contract = $employee->activeContractOn($date instanceof Carbon ? $date : Carbon::parse($date));

        return $contract ? $contract->expectedMinutesForDate(Carbon::parse($date->toDateString())) : 0;
    }

    public function approvedAbsenceOn(Employee $employee, CarbonInterface $date): ?Absence
    {
        return $employee->absences()
            ->approved()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();
    }

    /**
     * Recompute one ledger day for an employee. Returns null (and removes any
     * existing row) for fully empty days, so weekends, future and contract-less
     * days don't clutter the account.
     */
    public function recalculateDay(Employee $employee, CarbonInterface $date): ?WorkDay
    {
        $day = Carbon::parse($date->toDateString());

        // Global "tracking active from" cut-off: days before go-live never build
        // a balance (guards against a mis-set contract start far in the past).
        $start = Setting::get('tracking_start');
        if ($start && $day->toDateString() < $start) {
            WorkDay::where('employee_id', $employee->id)
                ->where('work_date', $day->toDateString())->delete();

            return null;
        }

        $minutes = $this->dayMinutes($employee, $date);
        $worked = $minutes['net'];
        $contract = $employee->activeContractOn($day);
        $absence = $this->approvedAbsenceOn($employee, $date);

        // Expected time only within a contract, and not for future days.
        $expected = ($contract && ! $day->isAfter(Carbon::today()))
            ? $contract->expectedMinutesForDate($day)
            : 0;

        // The running day must not look like a shortfall: until it is over, the
        // expected time is capped at what has already been delivered, so the
        // balance sits at 0 while the employee is still working and only turns
        // positive once they pass their target. The full expectation applies from
        // the next day on (the nightly recalculation settles it).
        // Absence days are excluded: their balance ignores `expected` anyway, and
        // capping it would understate the month's Soll.
        if ($absence === null && $day->isToday()) {
            $expected = min($expected, $worked);
        }

        if (! $contract) {
            // No active contract → presence is recorded as Ist, but the day does
            // not build any Soll/Saldo (otherwise legacy data inflates the balance).
            $storedExpected = 0;
            $rawBalance = 0;
        } else {
            $storedExpected = ($absence && $absence->type === Absence::TYPE_UNPAID) ? 0 : $expected;
            $rawBalance = match (true) {
                $absence === null => $worked - $expected,
                $absence->type === Absence::TYPE_VACATION,
                $absence->type === Absence::TYPE_SPECIAL,
                $absence->type === Absence::TYPE_SICK => $worked,
                $absence->type === Absence::TYPE_UNPAID => $worked,
                $absence->type === Absence::TYPE_OVERTIME => $worked - $expected,
                default => $worked - $expected,
            };
        }

        $balance = $this->applyTolerance(
            $rawBalance,
            $contract?->balanceTolerance() ?? Contract::globalBalanceTolerance(),
        );

        // Drop completely empty days (no work, no Soll, no absence).
        if ($minutes['gross'] === 0 && $storedExpected === 0 && $absence === null) {
            WorkDay::where('employee_id', $employee->id)
                ->where('work_date', $day->toDateString())->delete();

            return null;
        }

        return WorkDay::updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $day->toDateString()],
            [
                'gross_minutes' => $minutes['gross'],
                'break_minutes' => $minutes['break'],
                'worked_minutes' => $worked,
                'expected_minutes' => $storedExpected,
                'balance_minutes' => $balance,
                'raw_balance_minutes' => $rawBalance,
                'absence_id' => $absence?->id,
            ],
        );
    }

    public function recalculateRange(Employee $employee, CarbonInterface $from, CarbonInterface $to): int
    {
        $cursor = Carbon::parse($from->toDateString());
        $end = Carbon::parse($to->toDateString());
        $count = 0;
        while ($cursor->lte($end)) {
            $this->recalculateDay($employee, $cursor);
            $cursor->addDay();
            $count++;
        }

        return $count;
    }

    /** Recompute every day covered by an absence (used on approve/reject). */
    public function recalculateForAbsence(Absence $absence): void
    {
        $this->recalculateRange($absence->employee, $absence->start_date, $absence->end_date);
    }

    /**
     * Recompute the ledger for the employee a (manually edited) stamping belongs
     * to, on its date. Attribution follows the log's stamped `employee_id`, not
     * the card's current holder. No-op for logs not linked to an employee.
     */
    public function recalculateForLog(UserLog $log): void
    {
        if (! $log->employee_id || ! $log->checkindate) {
            return;
        }
        $employee = Employee::find($log->employee_id);
        if ($employee) {
            $this->recalculateDay($employee, Carbon::parse(substr((string) $log->checkindate, 0, 10)));
        }
    }
}
