<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\UserLog;
use App\Services\BalanceFormat;
use App\Services\WorktimeReport;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The holiday lookup caches statically; reset it so DB rollbacks between
        // tests can't leak holidays into an unrelated test.
        \App\Models\Holiday::flushCache();
        // Same for the memoized balance format.
        \App\Services\BalanceFormat::forget();
    }

    private function makeEmployee(string $role = Employee::ROLE_EMPLOYEE, string $email = 'e@example.de'): Employee
    {
        return Employee::create([
            'name' => 'Test '.$role,
            'email' => $email,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_admin_sees_all_pages_employee_is_restricted(): void
    {
        $this->seed();
        $admin = Employee::where('email', 'admin@example.de')->first();
        $employee = Employee::where('email', 'max@example.de')->first();

        $everyone = ['/admin', '/admin/absences', '/admin/work-days', '/admin/check-in-out', '/admin/worktime-report-page'];
        $managerOnly = ['/admin/employees', '/admin/devices', '/admin/cardholders', '/admin/user-logs', '/admin/user-logs/create', '/admin/holidays', '/admin/manage-settings', '/admin/enroll-card'];

        // Admin reaches everything.
        foreach ([...$everyone, ...$managerOnly] as $url) {
            $this->actingAs($admin)->get($url)->assertSuccessful();
        }

        // Switch user: flush the session so AuthenticateSession does not log us
        // out on the password-hash mismatch from the previous user.
        $this->flushSession();

        foreach ($everyone as $url) {
            $this->actingAs($employee)->get($url)->assertSuccessful();
        }
        foreach ($managerOnly as $url) {
            $this->actingAs($employee)->get($url)->assertForbidden();
        }
    }

    public function test_device_api_two_cards_resolve_to_same_employee(): void
    {
        $this->seed();

        foreach (['deadbeef', 'beefcafe'] as $uid) {
            // Each starts a fresh in/out cycle on its own day-state.
            $r = $this->get("/getdata.php?device_token=a1b2c3d4e5f60718&card_uid={$uid}");
            $r->assertStatus(200);
            $this->assertStringContainsString('Max Mustermann', $r->getContent());
        }

        $this->get('/getdata.php?device_token=ffffffffffffffff&card_uid=deadbeef')
            ->assertStatus(503)->assertSee('Error: Gerät nicht gefunden');
    }

    public function test_expected_minutes_per_worktime_model(): void
    {
        $monday = Carbon::parse('2026-06-08');   // workday
        $sunday = Carbon::parse('2026-06-07');   // non-workday
        $base = ['valid_from' => '2024-01-01', 'workdays' => [1, 2, 3, 4, 5]];

        $daily = new Contract($base + ['worktime_model' => Contract::MODEL_DAILY, 'target_hours' => 8]);
        $this->assertSame(480, $daily->expectedMinutesForDate($monday));
        $this->assertSame(0, $daily->expectedMinutesForDate($sunday));

        $weekly = new Contract($base + ['worktime_model' => Contract::MODEL_WEEKLY, 'target_hours' => 40]);
        $this->assertSame(480, $weekly->expectedMinutesForDate($monday)); // 40h/5

        $tracking = new Contract($base + ['worktime_model' => Contract::MODEL_TRACKING]);
        $this->assertSame(0, $tracking->expectedMinutesForDate($monday));

        $monthly = new Contract($base + ['worktime_model' => Contract::MODEL_MONTHLY, 'target_hours' => 160]);
        $this->assertGreaterThan(0, $monthly->expectedMinutesForDate($monday));
        $this->assertSame(0, $monthly->expectedMinutesForDate($sunday));
    }

    public function test_worktime_report_pdf_access(): void
    {
        $this->seed();
        $admin = Employee::where('email', 'admin@example.de')->first();
        $employee = Employee::where('email', 'max@example.de')->first();

        // Manager may download any employee's report.
        $resp = $this->actingAs($admin)->get(route('reports.worktime', [
            'employee' => $employee->id, 'year' => now()->year, 'month' => now()->month,
        ]));
        $resp->assertSuccessful();
        $this->assertStringContainsString('application/pdf', $resp->headers->get('content-type'));

        // Employee may not download someone else's report.
        $other = $this->makeEmployee(Employee::ROLE_EMPLOYEE, 'other@example.de');
        $this->actingAs($other)->get(route('reports.worktime', [
            'employee' => $employee->id, 'year' => now()->year, 'month' => now()->month,
        ]))->assertForbidden();
    }

    public function test_public_holiday_on_workday_has_zero_expected(): void
    {
        $monday = Carbon::parse('2026-06-08');   // a workday
        $base = ['valid_from' => '2024-01-01', 'workdays' => [1, 2, 3, 4, 5]];
        $daily = new Contract($base + ['worktime_model' => Contract::MODEL_DAILY, 'target_hours' => 8]);

        // Without a holiday: full Soll.
        $this->assertSame(480, $daily->expectedMinutesForDate($monday));

        // Mark it a holiday -> Soll becomes 0 (paid day off, no negative balance).
        \App\Models\Holiday::create(['date' => '2026-06-08', 'name' => 'Test-Feiertag', 'source' => 'manual']);
        \App\Models\Holiday::flushCache();

        $this->assertSame(0, $daily->expectedMinutesForDate($monday));
        $this->assertSame(480, $daily->expectedMinutesForDate(Carbon::parse('2026-06-09')));
    }

    public function test_recalc_balances_for_worked_day_and_absence_day(): void
    {
        $employee = $this->makeEmployee();
        $employee->contracts()->create([
            'valid_from' => '2024-01-01',
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
            'vacation_days_per_year' => 30,
        ]);
        $employee->cards()->create([
            'card_uid' => 'AABBCCDD', 'username' => $employee->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-08',
        ]);

        // A worked Monday: 08:00–16:30 = 510 minutes stamped, minus the 30 min
        // statutory break (>6 h, none stamped out) = 480 net -> target met.
        UserLog::create([
            'employee_id' => $employee->id, 'card_uid' => 'AABBCCDD',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '16:30:00', 'card_out' => 1,
        ]);

        $service = app(WorktimeService::class);
        $worked = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(510, $worked->gross_minutes);
        $this->assertSame(30, $worked->break_minutes);
        $this->assertSame(480, $worked->worked_minutes);
        $this->assertSame(480, $worked->expected_minutes);
        $this->assertSame(0, $worked->balance_minutes);

        // An approved vacation Tuesday neutralizes the balance (credited).
        $employee->absences()->create([
            'type' => Absence::TYPE_VACATION, 'start_date' => '2026-06-09',
            'end_date' => '2026-06-09', 'status' => Absence::STATUS_APPROVED,
        ]);
        $vac = $service->recalculateDay($employee, Carbon::parse('2026-06-09'));
        $this->assertSame(0, $vac->worked_minutes);
        $this->assertSame(0, $vac->balance_minutes);
        $this->assertNotNull($vac->absence_id);
    }

    /**
     * The staircase is applied per day against the stamped presence; time the
     * employee already stamped out counts towards it, and a day is never pushed
     * below the threshold that triggered the deduction.
     *
     * @dataProvider breakCases
     */
    public function test_break_deduction_per_day(int $gross, int $stamped, int $expected): void
    {
        $this->assertSame($expected, app(WorktimeService::class)
            ->breakDeduction($gross, $stamped, Contract::DEFAULT_BREAK_RULES));
    }

    public static function breakCases(): array
    {
        return [
            'unter 6 h, keine Pause' => [240, 0, 0],
            'exakt 6 h' => [360, 0, 0],
            'knapp über 6 h wird auf 6 h gekappt' => [375, 0, 15],
            '6:45 -> volle 30 min' => [405, 0, 30],
            '9 h durchgestempelt' => [540, 0, 30],
            'über 9 h -> 45 min' => [660, 0, 45],
            'gestempelte 30 min decken die Pflicht' => [510, 30, 0],
            'gestempelte 15 min -> nur 15 min Rest' => [525, 15, 15],
            'großzügig gestempelt -> kein Abzug' => [600, 60, 0],
            'knapp über 6 h mit 20 min gestempelt' => [370, 20, 10],
        ];
    }

    /**
     * The tolerance is a threshold, not a deduction: below it a day counts as 0,
     * at or above it the full balance counts.
     *
     * @dataProvider toleranceCases
     */
    public function test_balance_tolerance(int $balance, int $tolerance, int $expected): void
    {
        $this->assertSame($expected, app(WorktimeService::class)->applyTolerance($balance, $tolerance));
    }

    public static function toleranceCases(): array
    {
        return [
            '+4 min verfällt' => [4, 5, 0],
            '-4 min verfällt' => [-4, 5, 0],
            'exakt +5 min zählt voll' => [5, 5, 5],
            'exakt -5 min zählt voll' => [-5, 5, -5],
            '-30 min zählt voll, nicht gekürzt' => [-30, 5, -30],
            '+90 min zählt voll' => [90, 5, 90],
            'punktgenau 0' => [0, 5, 0],
            'Toleranz 0 schaltet ab' => [1, 0, 1],
            'größere Toleranz' => [-14, 15, 0],
        ];
    }

    /**
     * The operator picks how balances read; the same minutes render three ways.
     *
     * @dataProvider balanceFormatCases
     */
    public function test_balance_format_follows_the_setting(string $format, int $minutes, string $expected): void
    {
        Setting::put('overtime_format', $format);
        BalanceFormat::forget();

        $this->assertSame($expected, BalanceFormat::make($minutes));
    }

    public static function balanceFormatCases(): array
    {
        return [
            'dezimal: 90 min sind anderthalb Stunden' => [BalanceFormat::DECIMAL, 90, '1,5 h'],
            'dezimal: glatte Werte bleiben glatt' => [BalanceFormat::DECIMAL, 480, '8 h'],
            'dezimal: zwei Stellen halten die Minute' => [BalanceFormat::DECIMAL, 14, '0,23 h'],
            'dezimal: Vorzeichen bleibt' => [BalanceFormat::DECIMAL, -90, '-1,5 h'],
            'dezimal: Null' => [BalanceFormat::DECIMAL, 0, '0 h'],
            'hhmm' => [BalanceFormat::HHMM, 90, '1:30'],
            'hhmm: Vorzeichen bleibt' => [BalanceFormat::HHMM, -90, '-1:30'],
            'hhmm: Null' => [BalanceFormat::HHMM, 0, '0:00'],
            'minuten' => [BalanceFormat::MINUTES, 90, '90 min'],
            'minuten: Tausenderpunkt' => [BalanceFormat::MINUTES, 1234, '1.234 min'],
            'minuten: Vorzeichen bleibt' => [BalanceFormat::MINUTES, -90, '-90 min'],
            'unbekannter Wert faellt auf den Standard zurueck' => ['bogus', 90, '1,5 h'],
        ];
    }

    /** Without the setting written, balances read as decimal hours. */
    public function test_balance_format_defaults_to_decimal_hours(): void
    {
        $this->assertSame(BalanceFormat::DECIMAL, BalanceFormat::current());
        $this->assertSame('1,5 h', BalanceFormat::make(90));
    }

    /** Ist/Soll/Pause are spans, not deviations — the setting must not touch them. */
    public function test_hhmm_stays_hhmm_whatever_the_setting_says(): void
    {
        Setting::put('overtime_format', BalanceFormat::MINUTES);
        BalanceFormat::forget();

        $this->assertSame('8:00', WorktimeReport::hhmm(480));
        $this->assertSame('480 min', WorktimeReport::saldo(480));
    }

    /** Contract with a vacation entitlement, valid from the given date. */
    private function makeEmployeeWithVacation(?float $days, string $validFrom = '2024-01-01', string $email = 'v@example.de'): Employee
    {
        $employee = $this->makeEmployee(Employee::ROLE_EMPLOYEE, $email);
        $employee->contracts()->create([
            'valid_from' => $validFrom,
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
            'vacation_days_per_year' => $days,
        ]);

        return $employee;
    }

    private function approveVacation(Employee $employee, string $start, string $end, bool $halfDay = false): void
    {
        $employee->absences()->create([
            'type' => Absence::TYPE_VACATION,
            'start_date' => $start,
            'end_date' => $end,
            'status' => Absence::STATUS_APPROVED,
            'half_day' => $halfDay,
        ]);
    }

    /** Vacation is spent in workdays — a Mon-Sun request costs five days, not seven. */
    public function test_vacation_skips_weekends_and_holidays(): void
    {
        $employee = $this->makeEmployeeWithVacation(30);

        // 2026-03-02 is a Monday; 03-08 the Sunday after.
        $this->approveVacation($employee, '2026-03-02', '2026-03-08');
        $this->assertSame(5.0, $employee->vacationTaken(2026));

        // Good Friday 2026 (2026-04-03) falls inside Mon-Fri and must not count.
        \App\Models\Holiday::create(['date' => '2026-04-03', 'name' => 'Karfreitag', 'region' => 'DE-SN']);
        \App\Models\Holiday::flushCache();
        $this->approveVacation($employee, '2026-03-30', '2026-04-03');

        $this->assertSame(9.0, $employee->vacationTaken(2026), 'vier Arbeitstage plus Karfreitag frei');
    }

    /** A half day is only meant for a single-day request. */
    public function test_half_day_vacation_counts_as_a_half_workday(): void
    {
        $employee = $this->makeEmployeeWithVacation(30);
        $this->approveVacation($employee, '2026-03-02', '2026-03-02', halfDay: true);

        $this->assertSame(0.5, $employee->vacationTaken(2026));
        $this->assertSame(29.5, $employee->vacationBalance(2026));
    }

    /**
     * The entitlement used to be read off a fixed June 1st probe, so anyone
     * hired later in the year silently got zero — and the tile then showed the
     * days taken as a negative "remaining" figure.
     */
    public function test_entitlement_is_found_for_a_contract_starting_late_in_the_year(): void
    {
        $employee = $this->makeEmployeeWithVacation(30, validFrom: '2026-08-01');
        $this->approveVacation($employee, '2026-09-01', '2026-09-04');

        $this->assertSame(30.0, $employee->vacationEntitlement(2026));
        $this->assertSame(4.0, $employee->vacationTaken(2026));
        $this->assertSame(26.0, $employee->vacationBalance(2026));
    }

    /** Without an entitlement on file there is no remainder to report — not a negative one. */
    public function test_missing_entitlement_reads_as_unknown_not_as_a_negative_balance(): void
    {
        $employee = $this->makeEmployeeWithVacation(null);
        $this->approveVacation($employee, '2026-03-02', '2026-03-06');

        $this->assertNull($employee->vacationEntitlement(2026));
        $this->assertNull($employee->vacationBalance(2026));
        $this->assertSame(5.0, $employee->vacationTaken(2026));
    }

    /**
     * The April report is the state as of April 30th: a July holiday booked
     * months ahead must not already show up in it.
     */
    public function test_report_counts_vacation_only_up_to_the_month_shown(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 12:00:00'));

        $employee = $this->makeEmployeeWithVacation(30);
        $this->approveVacation($employee, '2026-03-02', '2026-03-06');  // 5 Tage, vor April
        $this->approveVacation($employee, '2026-07-06', '2026-07-17');  // 10 Tage Sommerurlaub

        $april = app(WorktimeReport::class)->forMonth($employee, 2026, 4);
        $this->assertSame('2026-04-30', $april['as_of']->toDateString());
        $this->assertSame(5.0, $april['vacation_taken'], 'der Sommerurlaub war im April noch nicht');
        $this->assertSame(25.0, $april['vacation_left']);

        $september = app(WorktimeReport::class)->forMonth($employee, 2026, 9);
        $this->assertSame('2026-09-21', $september['as_of']->toDateString(), 'laufender Monat: heute');
        $this->assertSame(15.0, $september['vacation_taken']);
        $this->assertSame(15.0, $september['vacation_left']);
    }

    /** A request straddling the cut-off counts only with the days before it. */
    public function test_vacation_across_the_cut_off_counts_only_the_days_within(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 12:00:00'));

        $employee = $this->makeEmployeeWithVacation(30);
        // Mo 2026-01-26 bis Fr 2026-02-06: je fünf Arbeitstage links und rechts
        // des Monatswechsels, und kein Feiertag dazwischen.
        $this->approveVacation($employee, '2026-01-26', '2026-02-06');

        $this->assertSame(5.0, app(WorktimeReport::class)->forMonth($employee, 2026, 1)['vacation_taken']);
        $this->assertSame(10.0, $employee->vacationTaken(2026));
    }

    /** A request starting in December counts its January days towards the new year. */
    public function test_vacation_across_new_year_counts_in_both_years(): void
    {
        $employee = $this->makeEmployeeWithVacation(30);
        // Mo 2025-12-29 bis Fr 2026-01-02.
        $this->approveVacation($employee, '2025-12-29', '2026-01-02');

        $this->assertSame(3.0, $employee->vacationTaken(2025), '29., 30., 31.12.');
        $this->assertSame(2.0, $employee->vacationTaken(2026), '1. und 2.1.');
    }

    /**
     * The year balance stops at the cut-off too, not at today.
     *
     * Ohne Vertrag baut der Bericht selbst kein Soll auf, sodass allein die hier
     * gesetzte Juni-Zeile zählt — sie liegt außerhalb beider Anzeigefenster und
     * wird vom Neuberechnen nicht angefasst.
     */
    public function test_year_balance_stops_at_the_month_shown(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 12:00:00'));

        $employee = $this->makeEmployee();
        $employee->workDays()->create([
            'work_date' => '2026-06-15', 'period' => '2026-06',
            'worked_minutes' => 600, 'expected_minutes' => 480, 'balance_minutes' => 120,
            'raw_balance_minutes' => 120, 'break_minutes' => 0,
        ]);

        $april = app(WorktimeReport::class)->forMonth($employee, 2026, 4);
        $this->assertSame(0, $april['year_balance'], 'der Juni zählt im April-Nachweis nicht mit');
        $this->assertSame(0, $april['total_balance']);

        $september = app(WorktimeReport::class)->forMonth($employee, 2026, 9);
        $this->assertSame(120, $september['year_balance'], 'bis heute gerechnet ist er dabei');
    }

    private function bookAdjustment(Employee $employee, string $date, int $minutes, ?string $note = null): void
    {
        \App\Models\BalanceAdjustment::create([
            'employee_id' => $employee->id,
            'effective_date' => $date,
            'minutes' => $minutes,
            'note' => $note,
        ]);
    }

    /** A worked day plus a correction booked alongside it. */
    private function employeeWithLedgerDay(int $balanceMinutes, string $date = '2026-06-15'): Employee
    {
        $employee = $this->makeEmployee();
        $employee->workDays()->create([
            'work_date' => $date, 'period' => substr($date, 0, 7),
            'worked_minutes' => 480 + $balanceMinutes, 'expected_minutes' => 480,
            'balance_minutes' => $balanceMinutes, 'raw_balance_minutes' => $balanceMinutes,
            'break_minutes' => 0,
        ]);

        return $employee;
    }

    /** Corrections move the counter without touching the ledger. */
    public function test_balance_adjustment_shifts_the_overtime_counter(): void
    {
        $employee = $this->employeeWithLedgerDay(600);
        $this->assertSame(600, $employee->overtimeBalanceMinutes());

        $this->bookAdjustment($employee, '2026-07-01', -600, 'Altbestand bereinigt');

        $this->assertSame(0, $employee->fresh()->overtimeBalanceMinutes());
        $this->assertSame(1, $employee->workDays()->count(), 'das Arbeitszeitkonto bleibt unangetastet');
        $this->assertSame(600, (int) $employee->workDays()->sum('balance_minutes'));
    }

    /** The cut-off applies to corrections too, so a report shows the state of its month. */
    public function test_balance_adjustment_respects_the_cut_off(): void
    {
        $employee = $this->employeeWithLedgerDay(600, '2026-02-16');
        $this->bookAdjustment($employee, '2026-07-01', -600);

        $this->assertSame(600, $employee->overtimeBalanceMinutes(Carbon::parse('2026-04-30')));
        $this->assertSame(0, $employee->overtimeBalanceMinutes(Carbon::parse('2026-08-31')));
    }

    /**
     * "Set the balance" books the difference against the current state, so
     * running it twice must not deduct twice.
     */
    public function test_setting_the_balance_twice_does_not_deduct_twice(): void
    {
        $employee = $this->employeeWithLedgerDay(600);
        $asOf = Carbon::parse('2026-12-31');

        $delta = 0 - $employee->overtimeBalanceMinutes($asOf);
        $this->bookAdjustment($employee, $asOf->toDateString(), $delta);
        $this->assertSame(0, $employee->fresh()->overtimeBalanceMinutes($asOf));

        // Zweiter Lauf: die Differenz ist jetzt 0, es darf nichts mehr gebucht werden.
        $this->assertSame(0, 0 - $employee->fresh()->overtimeBalanceMinutes($asOf));
    }

    /** The employee edit page renders with the corrections tab attached. */
    public function test_employee_page_renders_with_the_adjustments_tab(): void
    {
        $this->seed();
        $admin = Employee::where('email', 'admin@example.de')->first();
        $employee = Employee::where('email', 'max@example.de')->first();
        $this->bookAdjustment($employee, '2026-01-01', -1_200, 'Altbestand bereinigt');

        $this->actingAs($admin)
            ->get('/admin/employees/'.$employee->id.'/edit')
            ->assertSuccessful();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\EmployeeResource\RelationManagers\BalanceAdjustmentsRelationManager::class, [
                'ownerRecord' => $employee,
                'pageClass' => \App\Filament\Resources\EmployeeResource\Pages\EditEmployee::class,
            ])
            ->assertSuccessful()
            ->assertSee('Altbestand bereinigt');
    }

    /**
     * The "Saldo setzen" button through the actual UI: it books the difference,
     * and running it a second time books nothing because there is none left.
     */
    public function test_set_balance_action_books_the_difference_once(): void
    {
        $admin = $this->makeEmployee(Employee::ROLE_ADMIN, 'boss@example.de');
        $employee = $this->employeeWithLedgerDay(1_200, '2026-06-15');

        $run = fn () => \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\EmployeeResource\RelationManagers\BalanceAdjustmentsRelationManager::class, [
                'ownerRecord' => $employee,
                'pageClass' => \App\Filament\Resources\EmployeeResource\Pages\EditEmployee::class,
            ])
            ->callTableAction('setBalance', data: [
                'effective_date' => '2026-12-31',
                'minutes' => 0,
                'note' => 'Altbestand bereinigt',
            ])
            ->assertHasNoTableActionErrors();

        $run();
        $this->assertSame(1, $employee->balanceAdjustments()->count());
        $this->assertSame(-1_200, (int) $employee->balanceAdjustments()->sum('minutes'));
        $this->assertSame(0, $employee->fresh()->overtimeBalanceMinutes());
        $this->assertSame($admin->id, $employee->balanceAdjustments()->first()->created_by);

        $run();
        $this->assertSame(1, $employee->balanceAdjustments()->count(), 'kein zweiter Abzug');
        $this->assertSame(0, $employee->fresh()->overtimeBalanceMinutes());
    }

    /** A correction booked on January 1st belongs to the new year, not the carryover. */
    public function test_new_year_correction_lands_in_the_new_year_not_the_carryover(): void
    {
        $this->travelTo(Carbon::parse('2027-03-15 12:00:00'));

        $employee = $this->employeeWithLedgerDay(600, '2026-06-15');
        $this->bookAdjustment($employee, '2027-01-01', -600, 'Altbestand bereinigt');

        $report = app(WorktimeReport::class)->forMonth($employee, 2027, 3);

        $this->assertSame(600, $report['carryover'], 'das Vorjahr bleibt, wie es war');
        $this->assertSame(-600, $report['year_balance'], 'die Bereinigung zählt ins neue Jahr');
        $this->assertSame(0, $report['total_balance']);
    }

    /** Booked on December 31st it belongs to the old year instead. */
    public function test_year_end_correction_lands_in_the_carryover(): void
    {
        $this->travelTo(Carbon::parse('2027-03-15 12:00:00'));

        $employee = $this->employeeWithLedgerDay(600, '2026-06-15');
        $this->bookAdjustment($employee, '2026-12-31', -600);

        $report = app(WorktimeReport::class)->forMonth($employee, 2027, 3);

        $this->assertSame(0, $report['carryover']);
        $this->assertSame(0, $report['year_balance']);
        $this->assertSame(0, $report['total_balance']);
    }

    /**
     * @dataProvider dayFormatCases
     */
    public function test_day_counts_render_without_noise(float $days, string $expected): void
    {
        $this->assertSame($expected, Absence::formatDays($days));
    }

    public static function dayFormatCases(): array
    {
        return [
            'glatt' => [30.0, '30'],
            'halber Tag' => [4.5, '4,5'],
            'null' => [0.0, '0'],
            'zweistellig glatt' => [50.0, '50'],
            'dreistellig glatt' => [100.0, '100'],
        ];
    }

    /**
     * While today is still running, the open stamping counts up to "now" and the
     * expected time is capped at it — so the day never looks like a shortfall.
     */
    public function test_running_day_counts_open_stamping_and_never_shows_a_shortfall(): void
    {
        // 2026-06-08 is a Monday; freeze midday so the assertions are deterministic.
        $this->travelTo(Carbon::parse('2026-06-08 12:00:00'));

        $employee = $this->makeEmployee();
        $employee->contracts()->create([
            'valid_from' => '2024-01-01',
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
        ]);
        $employee->cards()->create([
            'card_uid' => 'BEEF0004', 'username' => $employee->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-01',
        ]);
        // Checked in at 08:00, no checkout yet.
        UserLog::create([
            'employee_id' => $employee->id, 'card_uid' => 'BEEF0004',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '00:00:00', 'card_out' => 0,
        ]);

        $service = app(WorktimeService::class);

        // Midday: 4 h delivered, Soll auf diese 4 h begrenzt -> kein Minus.
        $wd = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(240, $wd->gross_minutes);
        $this->assertSame(240, $wd->worked_minutes);
        $this->assertSame(240, $wd->expected_minutes);
        $this->assertSame(0, $wd->balance_minutes);

        // Abends noch eingestempelt: 9:30 Anwesenheit, 30 min Pause (auf 9:00
        // gekappt) -> 9:00 netto, volles Soll greift wieder, +1:00 Saldo.
        $this->travelTo(Carbon::parse('2026-06-08 17:30:00'));
        $wd = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(540, $wd->worked_minutes);
        $this->assertSame(480, $wd->expected_minutes);
        $this->assertSame(60, $wd->balance_minutes);

        // Am Folgetag zählt die vergessene Stempelung nicht mehr mit: der Tag
        // wächst nicht stillschweigend weiter.
        $this->travelTo(Carbon::parse('2026-06-09 09:00:00'));
        $wd = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(0, $wd->gross_minutes);
        $this->assertSame(-480, $wd->balance_minutes, 'offener Vortag verfällt, volles Soll schlägt durch');
    }

    public function test_contract_tolerance_overrides_the_global_default(): void
    {
        $employee = $this->makeEmployee();
        $employee->contracts()->create([
            'valid_from' => '2024-01-01',
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
            'balance_tolerance_minutes' => 20,
        ]);
        $employee->cards()->create([
            'card_uid' => 'BEEF0003', 'username' => $employee->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-01',
        ]);
        // 08:00-16:44 = 524 gross, -30 Pause = 494 netto, Soll 480 -> +14 Saldo.
        UserLog::create([
            'employee_id' => $employee->id, 'card_uid' => 'BEEF0003',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '16:44:00', 'card_out' => 1,
        ]);

        $wd = app(WorktimeService::class)->recalculateDay($employee, Carbon::parse('2026-06-08'));

        // Unter der Vertragstoleranz von 20 -> zählt nicht, Rohwert bleibt sichtbar.
        $this->assertSame(14, $wd->raw_balance_minutes);
        $this->assertSame(0, $wd->balance_minutes);
        $this->assertSame(0, $employee->fresh()->overtimeBalanceMinutes());
    }

    public function test_contract_break_rules_override_the_global_default(): void
    {
        $employee = $this->makeEmployee();
        $employee->contracts()->create([
            'valid_from' => '2024-01-01',
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
            'break_rules' => [['from_minutes' => 240, 'minutes' => 60]],
        ]);
        $employee->cards()->create([
            'card_uid' => 'BEEF0001', 'username' => $employee->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-01',
        ]);
        UserLog::create([
            'employee_id' => $employee->id, 'card_uid' => 'BEEF0001',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '16:00:00', 'card_out' => 1,
        ]);

        // The contract's own staircase wins: 8 h stamped - 60 min = 420 net.
        $wd = app(WorktimeService::class)->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(480, $wd->gross_minutes);
        $this->assertSame(60, $wd->break_minutes);
        $this->assertSame(420, $wd->worked_minutes);
    }

    public function test_stamped_out_lunch_is_not_deducted_twice(): void
    {
        $employee = $this->makeEmployee();
        $employee->contracts()->create([
            'valid_from' => '2024-01-01',
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
        ]);
        $employee->cards()->create([
            'card_uid' => 'BEEF0002', 'username' => $employee->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-01',
        ]);
        // 08:00-12:00 and 12:30-17:00 -> 8:30 present, 30 min stamped out.
        foreach ([['08:00:00', '12:00:00'], ['12:30:00', '17:00:00']] as [$in, $out]) {
            UserLog::create([
                'employee_id' => $employee->id, 'card_uid' => 'BEEF0002',
                'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
                'timein' => $in, 'timeout' => $out, 'card_out' => 1,
            ]);
        }

        $wd = app(WorktimeService::class)->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(510, $wd->gross_minutes);
        $this->assertSame(0, $wd->break_minutes, 'stamped lunch already covers the statutory break');
        $this->assertSame(510, $wd->worked_minutes);
    }

    public function test_chip_reassignment_keeps_history_with_original_employee(): void
    {
        $service = app(WorktimeService::class);

        $alice = $this->makeEmployee(Employee::ROLE_EMPLOYEE, 'alice@example.de');
        $bob = $this->makeEmployee(Employee::ROLE_EMPLOYEE, 'bob@example.de');

        // One physical card, now (currently) held by Bob after a handover.
        $bob->cards()->create([
            'card_uid' => 'CAFE0001', 'username' => $bob->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2024-01-01',
        ]);

        // A stamping from Alice's era (stamped to her at check-in: 8h) ...
        UserLog::create([
            'employee_id' => $alice->id, 'card_uid' => 'CAFE0001',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2022-05-10',
            'timein' => '08:00:00', 'timeout' => '16:00:00', 'card_out' => 1,
        ]);
        // ... and one from Bob's era on the same physical card (4h).
        UserLog::create([
            'employee_id' => $bob->id, 'card_uid' => 'CAFE0001',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2024-05-10',
            'timein' => '08:00:00', 'timeout' => '12:00:00', 'card_out' => 1,
        ]);

        // Alice keeps her old day; Bob does NOT inherit it despite now holding the
        // card. 8 h stamped minus the 30 min statutory break = 450 net.
        $this->assertSame(450, $service->workedMinutes($alice, Carbon::parse('2022-05-10')));
        $this->assertSame(0, $service->workedMinutes($bob, Carbon::parse('2022-05-10')));

        // Bob's own day stays his; Alice has nothing there.
        $this->assertSame(240, $service->workedMinutes($bob, Carbon::parse('2024-05-10')));
        $this->assertSame(0, $service->workedMinutes($alice, Carbon::parse('2024-05-10')));
    }

    public function test_days_without_contract_build_no_balance(): void
    {
        $employee = $this->makeEmployee(); // no contract
        $employee->cards()->create([
            'card_uid' => 'CC11DD22', 'username' => $employee->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-01',
        ]);
        UserLog::create([
            'employee_id' => $employee->id, 'card_uid' => 'CC11DD22',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '16:00:00', 'card_out' => 1,
        ]);

        $service = app(WorktimeService::class);

        // Worked day without a contract: Ist recorded (net of the global break
        // rule), but no Soll/Saldo.
        $wd = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertNotNull($wd);
        $this->assertSame(450, $wd->worked_minutes);
        $this->assertSame(0, $wd->expected_minutes);
        $this->assertSame(0, $wd->balance_minutes);
        $this->assertSame(0, $employee->fresh()->overtimeBalanceMinutes());

        // Empty day: no ledger row at all.
        $this->assertNull($service->recalculateDay($employee, Carbon::parse('2026-06-07')));
    }

    public function test_tracking_start_excludes_earlier_days(): void
    {
        $employee = $this->makeEmployee();
        $employee->contracts()->create([
            'valid_from' => '1990-01-01', 'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8, 'workdays' => [1, 2, 3, 4, 5],
        ]);
        \App\Models\Setting::put('tracking_start', '2026-06-01');

        $service = app(WorktimeService::class);

        // Workday before go-live → no row at all (contract goes back to 1990).
        $this->assertNull($service->recalculateDay($employee, Carbon::parse('2026-05-20')));

        // Workday after go-live with no work → counts as -Soll.
        $after = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertNotNull($after);
        $this->assertSame(-480, $after->balance_minutes);

        // "Saldo gesamt" only sums from the go-live date.
        $this->assertSame(-480, $employee->fresh()->overtimeBalanceMinutes());
    }

    public function test_absence_request_can_be_approved_by_hr(): void
    {
        $hr = $this->makeEmployee(Employee::ROLE_HR, 'hr@example.de');
        $employee = $this->makeEmployee(Employee::ROLE_EMPLOYEE, 'worker@example.de');
        $employee->contracts()->create([
            'valid_from' => '2024-01-01', 'worktime_model' => Contract::MODEL_TRACKING,
        ]);

        $absence = $employee->absences()->create([
            'type' => Absence::TYPE_VACATION, 'start_date' => '2026-07-01',
            'end_date' => '2026-07-03', 'status' => Absence::STATUS_PENDING,
        ]);

        \Livewire\Livewire::actingAs($hr)
            ->test(\App\Filament\Resources\AbsenceResource\Pages\ListAbsences::class)
            ->callTableAction('approve', $absence);

        $absence->refresh();
        $this->assertSame(Absence::STATUS_APPROVED, $absence->status);
        $this->assertSame($hr->id, $absence->approver_id);

        // A decision can be revised: an already-approved request can be rejected.
        \Livewire\Livewire::actingAs($hr)
            ->test(\App\Filament\Resources\AbsenceResource\Pages\ListAbsences::class)
            ->callTableAction('reject', $absence, data: ['decision_note' => 'Doch nicht']);

        $absence->refresh();
        $this->assertSame(Absence::STATUS_REJECTED, $absence->status);
        $this->assertSame('Doch nicht', $absence->decision_note);
    }
}
