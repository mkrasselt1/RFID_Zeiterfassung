<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\UserLog;
use App\Models\WorkDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Alles, was die Neuberechnung eines Zeitraums braucht — einmal geladen.
 *
 * Vorher holte jeder einzelne Tag seine Stempelungen, seinen Vertrag, eine
 * etwaige Abwesenheit, die vorhandene Ledger-Zeile und zwei Einstellungen
 * frisch aus der Datenbank: rund neun Abfragen je Mitarbeiter und Tag. Für
 * acht Leute und ein Jahr waren das über 25.000 Abfragen, und mehrere Jahre
 * liefen in den Zeitausfall der Seite.
 *
 * Hier wird all das je Mitarbeiter und Zeitraum einmal geholt und im Speicher
 * nachgeschlagen. Die Datenmenge ist überschaubar: ein Mitarbeiterjahr sind
 * ein paar hundert Stempelungen, eine Handvoll Verträge und ein paar
 * Abwesenheiten.
 */
class RecalculationWindow
{
    /** @var array<string, Collection<int, UserLog>> */
    private array $logsByDate;

    /** @var Collection<int, Contract> */
    private Collection $contracts;

    /** @var Collection<int, Absence> */
    private Collection $absences;

    /** @var array<string, WorkDay> */
    private array $existing;

    public readonly ?string $trackingStart;

    public readonly int $globalTolerance;

    public function __construct(Employee $employee, CarbonInterface $from, CarbonInterface $to)
    {
        $start = $from->toDateString();
        $end = $to->toDateString();

        $this->trackingStart = Setting::get('tracking_start');
        $this->globalTolerance = Contract::globalBalanceTolerance();

        $this->logsByDate = UserLog::where('employee_id', $employee->id)
            ->whereBetween('checkindate', [$start, $end])
            ->get()
            ->groupBy(fn (UserLog $log) => substr((string) $log->checkindate, 0, 10))
            ->all();

        // Alle Verträge, nicht nur die im Zeitraum: einer kann davor begonnen
        // haben und noch laufen.
        $this->contracts = $employee->contracts()->orderByDesc('valid_from')->get();

        $this->absences = $employee->absences()
            ->approved()
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        $this->existing = WorkDay::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->keyBy(fn (WorkDay $row) => substr((string) $row->work_date, 0, 10))
            ->all();
    }

    /** @return Collection<int, UserLog> */
    public function logsOn(string $date): Collection
    {
        return $this->logsByDate[$date] ?? new Collection();
    }

    /** Mirrors Employee::activeContractOn(), but without a query. */
    public function contractOn(string $date): ?Contract
    {
        foreach ($this->contracts as $contract) {
            $validFrom = substr((string) $contract->valid_from, 0, 10);
            $validTo = $contract->valid_to ? substr((string) $contract->valid_to, 0, 10) : null;

            if ($validFrom <= $date && ($validTo === null || $validTo >= $date)) {
                return $contract;
            }
        }

        return null;
    }

    public function absenceOn(string $date): ?Absence
    {
        foreach ($this->absences as $absence) {
            if (substr((string) $absence->start_date, 0, 10) <= $date
                && substr((string) $absence->end_date, 0, 10) >= $date) {
                return $absence;
            }
        }

        return null;
    }

    public function existingOn(string $date): ?WorkDay
    {
        return $this->existing[$date] ?? null;
    }

    /** Nach dem Anlegen einer Zeile, damit ein zweiter Lauf sie wiederfindet. */
    public function remember(string $date, WorkDay $row): void
    {
        $this->existing[$date] = $row;
    }

    public function forget(string $date): void
    {
        unset($this->existing[$date]);
    }
}
