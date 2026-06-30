<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Cardholder;
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
 */
class WorktimeService
{
    /** Worked minutes for all of the employee's cards on a date (completed logs). */
    public function workedMinutes(Employee $employee, CarbonInterface $date): int
    {
        $cardUids = $employee->cards()->pluck('card_uid');
        if ($cardUids->isEmpty()) {
            return 0;
        }

        $logs = UserLog::whereIn('card_uid', $cardUids)
            ->where('checkindate', $date->toDateString())
            ->where('card_out', 1)
            ->get();

        $minutes = 0;
        foreach ($logs as $log) {
            $minutes += $this->logMinutes($log);
        }

        return $minutes;
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

        $worked = $this->workedMinutes($employee, $date);
        $contract = $employee->activeContractOn($day);
        $absence = $this->approvedAbsenceOn($employee, $date);

        // Expected time only within a contract, and not for future days.
        $expected = ($contract && ! $day->isAfter(Carbon::today()))
            ? $contract->expectedMinutesForDate($day)
            : 0;

        if (! $contract) {
            // No active contract → presence is recorded as Ist, but the day does
            // not build any Soll/Saldo (otherwise legacy data inflates the balance).
            $storedExpected = 0;
            $balance = 0;
        } else {
            $storedExpected = ($absence && $absence->type === Absence::TYPE_UNPAID) ? 0 : $expected;
            $balance = match (true) {
                $absence === null => $worked - $expected,
                $absence->type === Absence::TYPE_VACATION,
                $absence->type === Absence::TYPE_SPECIAL,
                $absence->type === Absence::TYPE_SICK => $worked,
                $absence->type === Absence::TYPE_UNPAID => $worked,
                $absence->type === Absence::TYPE_OVERTIME => $worked - $expected,
                default => $worked - $expected,
            };
        }

        // Drop completely empty days (no work, no Soll, no absence).
        if ($worked === 0 && $storedExpected === 0 && $absence === null) {
            WorkDay::where('employee_id', $employee->id)
                ->where('work_date', $day->toDateString())->delete();

            return null;
        }

        return WorkDay::updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $day->toDateString()],
            [
                'worked_minutes' => $worked,
                'expected_minutes' => $storedExpected,
                'balance_minutes' => $balance,
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
     * Recompute the ledger for the employee owning a card, on a given date.
     * Used after an admin corrects a raw stamping. No-op for unlinked cards.
     */
    public function recalculateForCardDate(string $cardUid, ?string $date): void
    {
        if (! $date) {
            return;
        }
        $employeeId = Cardholder::where('card_uid', $cardUid)->value('employee_id');
        $employee = $employeeId ? Employee::find($employeeId) : null;
        if ($employee) {
            $this->recalculateDay($employee, Carbon::parse(substr((string) $date, 0, 10)));
        }
    }
}
