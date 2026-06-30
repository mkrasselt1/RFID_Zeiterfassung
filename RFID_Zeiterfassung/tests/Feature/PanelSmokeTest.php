<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\UserLog;
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

        // A worked Monday: 08:00–16:30 = 510 minutes (+30 over the 480 target).
        UserLog::create([
            'employee_id' => $employee->id, 'card_uid' => 'AABBCCDD',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '16:30:00', 'card_out' => 1,
        ]);

        $service = app(WorktimeService::class);
        $worked = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertSame(510, $worked->worked_minutes);
        $this->assertSame(480, $worked->expected_minutes);
        $this->assertSame(30, $worked->balance_minutes);

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

        // Alice keeps her old day; Bob does NOT inherit it despite now holding the card.
        $this->assertSame(480, $service->workedMinutes($alice, Carbon::parse('2022-05-10')));
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

        // Worked day without a contract: Ist recorded, but no Soll/Saldo.
        $wd = $service->recalculateDay($employee, Carbon::parse('2026-06-08'));
        $this->assertNotNull($wd);
        $this->assertSame(480, $wd->worked_minutes);
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
