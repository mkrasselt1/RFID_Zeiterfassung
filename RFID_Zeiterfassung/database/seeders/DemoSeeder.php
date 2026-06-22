<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\Cardholder;
use App\Models\Contract;
use App\Models\Device;
use App\Models\Employee;
use App\Models\UserLog;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Rich demo dataset: employees across every role and worktime model, multiple
 * cards, ~6 weeks of attendance, and a mix of absences. Run explicitly:
 *
 *   php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 *
 * Idempotent-ish: employees are matched by email; their generated attendance,
 * absences and ledger rows are cleared and rebuilt on each run.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class); // base admin, max, devices, settings

        $timeDevice = Device::where('device_mode', Device::MODE_TIME)->first()
            ?? Device::firstOrCreate(['device_uid' => 'a1b2c3d4e5f60718'], [
                'device_name' => 'Haupteingang', 'device_dep' => 'Buero',
                'device_date' => now()->toDateString(), 'device_mode' => Device::MODE_TIME,
            ]);

        $admin = Employee::where('email', 'admin@example.de')->first();

        // role, name, dep, worktime model, target hours, workdays, vacation/yr, #cards
        $people = [
            ['hr@example.de',     Employee::ROLE_HR,         'Petra Personal',   'Buero',      Contract::MODEL_DAILY,   8,   [1, 2, 3, 4, 5], 30, 1],
            ['chef@example.de',   Employee::ROLE_SUPERVISOR, 'Stefan Schmidt',   'Produktion', Contract::MODEL_WEEKLY,  40,  [1, 2, 3, 4, 5], 30, 1],
            ['max@example.de',    Employee::ROLE_EMPLOYEE,   'Max Mustermann',   'Buero',      Contract::MODEL_DAILY,   8,   [1, 2, 3, 4, 5], 30, 2],
            ['anna@example.de',   Employee::ROLE_EMPLOYEE,   'Anna Arbeit',      'Produktion', Contract::MODEL_WEEKLY,  40,  [1, 2, 3, 4, 5], 28, 2],
            ['bjoern@example.de', Employee::ROLE_EMPLOYEE,   'Björn Behrens',    'Buero',      Contract::MODEL_DAILY,   6,   [1, 2, 3, 4],    24, 1],
            ['clara@example.de',  Employee::ROLE_EMPLOYEE,   'Clara Klein',      'Produktion', Contract::MODEL_MONTHLY, 160, [1, 2, 3, 4, 5], 30, 1],
            ['david@example.de',  Employee::ROLE_EMPLOYEE,   'David Dauer',      'Buero',      Contract::MODEL_TRACKING, null, [1, 2, 3, 4, 5], 20, 1],
        ];

        $serial = 2000;
        $cardSeq = 0x1000;
        $employees = [];

        foreach ($people as [$email, $role, $name, $dep, $model, $hours, $workdays, $vac, $cardCount]) {
            $employee = Employee::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'role' => $role,
                    'personnel_number' => (string) $serial,
                    'supervisor_id' => $admin?->id,
                    'is_active' => true,
                ],
            );
            $employees[] = $employee;

            // Fresh single contract.
            $employee->contracts()->delete();
            $employee->contracts()->create([
                'title' => 'Vertrag '.now()->subYear()->year,
                'valid_from' => now()->subYear()->startOfYear()->toDateString(),
                'valid_to' => null,
                'worktime_model' => $model,
                'target_hours' => $hours,
                'workdays' => $workdays,
                'vacation_days_per_year' => $vac,
            ]);

            // Cards (clear prior generated ones for this employee first).
            $employee->cards()->delete();
            for ($c = 0; $c < $cardCount; $c++) {
                $uid = strtoupper(dechex($cardSeq++)).'CAFE';
                Cardholder::updateOrCreate(
                    ['card_uid' => $uid],
                    [
                        'username' => $name,
                        'serialnumber' => $serial,
                        'gender' => 'None',
                        'email' => $email,
                        'add_card' => 1,
                        'card_select' => 0,
                        'device_dep' => $dep,
                        'device_uid' => $timeDevice->device_uid,
                        'user_date' => now()->subMonths(6)->toDateString(),
                        'employee_id' => $employee->id,
                    ],
                );
            }
            $serial++;
        }

        // Absences (clear generated ones first via the demo employees).
        $byEmail = fn (string $e) => Employee::where('email', $e)->first();

        Absence::whereIn('employee_id', collect($employees)->pluck('id'))->delete();

        $this->makeAbsence($byEmail('anna@example.de'), Absence::TYPE_VACATION, now()->subDays(9), now()->subDays(5), Absence::STATUS_APPROVED, $admin, 'Kurzurlaub');
        $this->makeAbsence($byEmail('bjoern@example.de'), Absence::TYPE_SICK, now()->subDays(3), now()->subDays(2), Absence::STATUS_APPROVED, $admin, 'Erkältung');
        $this->makeAbsence($byEmail('max@example.de'), Absence::TYPE_OVERTIME, now()->subDays(6), now()->subDays(6), Absence::STATUS_APPROVED, $admin, 'Überstundenabbau');
        $this->makeAbsence($byEmail('clara@example.de'), Absence::TYPE_VACATION, now()->addDays(7), now()->addDays(11), Absence::STATUS_PENDING, null, 'Sommerurlaub');
        $this->makeAbsence($byEmail('anna@example.de'), Absence::TYPE_UNPAID, now()->addDays(3), now()->addDays(3), Absence::STATUS_PENDING, null, 'Privater Termin');

        // Attendance for the last ~6 weeks.
        $from = now()->subDays(42)->startOfDay();
        $to = now()->copy();

        // Import public holidays first (so attendance skips them and the ledger
        // reflects Soll = 0 on those days).
        \App\Models\Setting::put('holiday_region', 'DE-SN');
        $holidays = app(\App\Services\HolidayService::class);
        foreach (range($from->year, $to->year) as $year) {
            $holidays->sync($year, 'DE-SN');
        }

        foreach ($employees as $employee) {
            $this->generateAttendance($employee, $from, $to);
        }

        // Rebuild the delivered-worktime ledger.
        $service = app(WorktimeService::class);
        foreach ($employees as $employee) {
            $service->recalculateRange($employee, $from, $to);
        }

        $this->command?->info('Demo data: '.count($employees).' employees, attendance + absences + ledger built.');
    }

    private function makeAbsence(?Employee $employee, string $type, Carbon $start, Carbon $end, string $status, ?Employee $approver, string $reason): void
    {
        if (! $employee) {
            return;
        }
        $employee->absences()->create([
            'type' => $type,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => $status,
            'reason' => $reason,
            'approver_id' => $status === Absence::STATUS_APPROVED ? $approver?->id : null,
            'decided_at' => $status === Absence::STATUS_APPROVED ? now() : null,
        ]);
    }

    /** Create one completed attendance log per workday, with realistic over/under and the odd missing day. */
    private function generateAttendance(Employee $employee, Carbon $from, Carbon $to): void
    {
        $card = $employee->cards()->first();
        $contract = $employee->activeContractOn($to);
        if (! $card || ! $contract) {
            return;
        }

        // Clear previously generated logs for this employee's cards.
        UserLog::whereIn('card_uid', $employee->cards()->pluck('card_uid'))->delete();

        $approvedRanges = $employee->absences()->approved()->get();

        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $isWorkday = in_array($cursor->isoWeekday(), $contract->workdayList(), true);
            $covered = $approvedRanges->contains(fn (Absence $a) => $cursor->betweenIncluded($a->start_date, $a->end_date))
                || \App\Models\Holiday::isHoliday($cursor);

            // Tracking-only employees still attend Mon–Fri.
            $shouldWork = $contract->worktime_model === Contract::MODEL_TRACKING
                ? in_array($cursor->isoWeekday(), [1, 2, 3, 4, 5], true)
                : $isWorkday;

            if ($shouldWork && ! $covered && mt_rand(1, 100) <= 90) {
                $expected = $contract->expectedMinutesForDate($cursor->copy());
                $target = $expected > 0 ? $expected : 480; // tracking -> ~8h
                $worked = max(180, $target + mt_rand(-35, 55));

                $in = $cursor->copy()->setTime(8, 0)->addMinutes(mt_rand(-20, 25));
                $out = $in->copy()->addMinutes($worked);

                UserLog::create([
                    'username' => $employee->name,
                    'serialnumber' => (float) ($employee->personnel_number ?? 0),
                    'card_uid' => $card->card_uid,
                    'device_uid' => $card->device_uid,
                    'device_dep' => $card->device_dep,
                    'checkindate' => $cursor->toDateString(),
                    'timein' => $in->format('H:i:s'),
                    'timeout' => $out->format('H:i:s'),
                    'card_out' => 1,
                    'calendarEventId' => null,
                ]);
            }

            $cursor->addDay();
        }
    }
}
