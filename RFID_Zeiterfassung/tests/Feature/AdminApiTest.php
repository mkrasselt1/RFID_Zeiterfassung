<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\UserLog;
use App\Models\UserLogChange;
use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Holiday::flushCache();
        \App\Services\BalanceFormat::forget();
        \App\Models\Setting::flushCache();

        $this->admin = $this->makeEmployee(Employee::ROLE_ADMIN, 'api@example.de');
        $this->worker = $this->makeEmployee(Employee::ROLE_EMPLOYEE, 'worker@example.de');
        $this->worker->contracts()->create([
            'valid_from' => '2024-01-01',
            'worktime_model' => Contract::MODEL_DAILY,
            'target_hours' => 8,
            'workdays' => [1, 2, 3, 4, 5],
            'vacation_days_per_year' => 30,
        ]);
        $this->worker->cards()->create([
            'card_uid' => 'AABBCCDD', 'username' => $this->worker->name, 'add_card' => 1,
            'device_dep' => 'Buero', 'user_date' => '2026-06-08',
        ]);
    }

    private function makeEmployee(string $role, string $email): Employee
    {
        return Employee::create([
            'name' => 'Test '.$role,
            'email' => $email,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function asAdmin(): static
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('api@example.de:secret123'));
    }

    private function log(string $date, string $in, string $out): UserLog
    {
        return UserLog::create([
            'employee_id' => $this->worker->id, 'card_uid' => 'AABBCCDD',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => $date,
            'timein' => $in, 'timeout' => $out, 'card_out' => 1,
        ]);
    }

    public function test_requires_a_manager_account(): void
    {
        $this->getJson('/api/v1/admin/employees')->assertStatus(401);

        $this->withHeader('Authorization', 'Basic '.base64_encode('api@example.de:wrong'))
            ->getJson('/api/v1/admin/employees')->assertStatus(401);

        $this->withHeader('Authorization', 'Basic '.base64_encode('worker@example.de:secret123'))
            ->getJson('/api/v1/admin/employees')->assertStatus(403);

        $this->asAdmin()->getJson('/api/v1/admin/employees')
            ->assertOk()
            ->assertJsonFragment(['card_uid' => 'AABBCCDD']);
    }

    public function test_lists_stampings_in_range(): void
    {
        $this->log('2026-06-08', '08:00:00', '16:30:00');
        $this->log('2026-07-01', '08:00:00', '16:30:00');

        $this->asAdmin()
            ->getJson('/api/v1/admin/stampings?from=2026-06-01&to=2026-06-30&employee_id='.$this->worker->id)
            ->assertOk()
            ->assertJsonCount(1, 'stampings')
            ->assertJsonPath('stampings.0.timeout', '16:30:00');
    }

    public function test_correcting_a_stamping_recalculates_the_day(): void
    {
        // Doppelstempelung morgens: Gehen eine Minute nach Kommen.
        $log = $this->log('2026-06-08', '08:00:00', '08:01:00');

        $this->asAdmin()
            ->patchJson("/api/v1/admin/stampings/{$log->id}", ['timeout' => '16:30:00'])
            ->assertOk()
            ->assertJsonPath('stamping.timeout', '16:30:00');

        $day = WorkDay::where('employee_id', $this->worker->id)->where('work_date', '2026-06-08')->first();
        $this->assertSame(480, $day->worked_minutes);
    }

    public function test_rejects_timeout_before_timein(): void
    {
        $log = $this->log('2026-06-08', '08:00:00', '16:30:00');

        $this->asAdmin()
            ->patchJson("/api/v1/admin/stampings/{$log->id}", ['timeout' => '07:00:00'])
            ->assertStatus(422);
    }

    public function test_adds_a_missing_stamping_from_the_employees_card(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/admin/stampings', [
                'employee_id' => $this->worker->id, 'date' => '2026-06-09',
                'timein' => '08:00:00', 'timeout' => '16:30:00',
            ])
            ->assertCreated()
            ->assertJsonPath('stamping.card_uid', 'AABBCCDD')
            ->assertJsonPath('stamping.source', UserLog::SOURCE_CORRECTION);

        $day = WorkDay::where('employee_id', $this->worker->id)->where('work_date', '2026-06-09')->first();
        $this->assertSame(480, $day->worked_minutes);
    }

    public function test_deleting_a_double_stamping_recalculates_the_day(): void
    {
        $this->log('2026-06-08', '08:00:00', '16:30:00');
        $double = $this->log('2026-06-08', '16:31:00', '16:32:00');

        $this->asAdmin()->deleteJson("/api/v1/admin/stampings/{$double->id}")->assertOk();

        $this->assertNull(UserLog::find($double->id));
        $day = WorkDay::where('employee_id', $this->worker->id)->where('work_date', '2026-06-08')->first();
        $this->assertSame(480, $day->worked_minutes);
    }

    public function test_creates_an_approved_absence_once(): void
    {
        $payload = [
            'employee_id' => $this->worker->id, 'type' => Absence::TYPE_VACATION,
            'start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'half_day' => true,
        ];

        $this->asAdmin()->postJson('/api/v1/admin/absences', $payload)
            ->assertCreated()
            ->assertJsonPath('absence.status', Absence::STATUS_APPROVED)
            ->assertJsonPath('absence.half_day', true);

        $this->assertSame($this->admin->id, Absence::first()->approver_id);

        // Ein zweiter Lauf legt nichts doppelt an.
        $this->asAdmin()->postJson('/api/v1/admin/absences', $payload)->assertStatus(409);
        $this->assertSame(1, Absence::count());

        $this->asAdmin()->getJson('/api/v1/admin/absences?from=2026-06-01&to=2026-06-30')
            ->assertOk()->assertJsonCount(1, 'absences');
    }

    public function test_manual_changes_are_logged_with_author_and_channel(): void
    {
        // Angelegt ohne Anmeldung, wie ein Leser es tut: kein Protokolleintrag.
        $log = $this->log('2026-06-08', '08:00:00', '08:01:00');
        $this->assertSame(0, UserLogChange::count());

        $this->asAdmin()
            ->patchJson("/api/v1/admin/stampings/{$log->id}", ['timeout' => '16:30:00'])
            ->assertOk();
        $this->asAdmin()->deleteJson("/api/v1/admin/stampings/{$log->id}")->assertOk();

        $changes = UserLogChange::orderBy('id')->get();
        $this->assertCount(2, $changes);

        $this->assertSame(UserLogChange::ACTION_UPDATED, $changes[0]->action);
        $this->assertSame(UserLogChange::CHANNEL_API, $changes[0]->channel);
        $this->assertSame($this->admin->id, $changes[0]->changed_by);
        $this->assertSame($this->worker->id, $changes[0]->employee_id);
        $this->assertSame(['timeout' => '08:01:00'], $changes[0]->before);
        $this->assertSame(['timeout' => '16:30:00'], $changes[0]->after);

        $this->assertSame(UserLogChange::ACTION_DELETED, $changes[1]->action);
        $this->assertSame('08:00:00', $changes[1]->before['timein']);
        $this->assertNull($changes[1]->after);
    }

    public function test_panel_edits_are_logged_as_panel(): void
    {
        $log = $this->log('2026-06-08', '08:00:00', '16:30:00');

        $this->actingAs($this->admin);
        $log->update(['timein' => '07:45:00']);

        $change = UserLogChange::sole();
        $this->assertSame(UserLogChange::CHANNEL_PANEL, $change->channel);
        $this->assertSame(['timein' => '07:45:00'], $change->after);
        $this->actingAs($this->admin)->get('/admin/user-log-changes')->assertSuccessful();
    }

    public function test_reassigns_a_stamping_made_with_someone_elses_card(): void
    {
        $other = $this->makeEmployee(Employee::ROLE_EMPLOYEE, 'other@example.de');
        $log = UserLog::create([
            'employee_id' => $other->id, 'card_uid' => 'FFFF0000',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2026-06-08',
            'timein' => '08:00:00', 'timeout' => '16:30:00', 'card_out' => 1,
        ]);

        $this->asAdmin()
            ->patchJson("/api/v1/admin/stampings/{$log->id}", ['employee_id' => $this->worker->id])
            ->assertOk()
            ->assertJsonPath('stamping.employee_id', $this->worker->id);

        $day = WorkDay::where('employee_id', $this->worker->id)->where('work_date', '2026-06-08')->first();
        $this->assertSame(480, $day->worked_minutes);
        $this->assertSame(['employee_id' => $this->worker->id], UserLogChange::sole()->after);
    }

    public function test_approves_a_pending_absence(): void
    {
        $absence = $this->worker->absences()->create([
            'type' => Absence::TYPE_VACATION, 'start_date' => '2026-06-10',
            'end_date' => '2026-06-10', 'status' => Absence::STATUS_PENDING,
        ]);

        $this->asAdmin()->postJson("/api/v1/admin/absences/{$absence->id}/approve")
            ->assertOk()
            ->assertJsonPath('absence.status', Absence::STATUS_APPROVED);

        $this->assertSame($this->admin->id, $absence->fresh()->approver_id);
        $this->assertNotNull(WorkDay::where('employee_id', $this->worker->id)->where('work_date', '2026-06-10')->value('absence_id'));
    }

    public function test_creates_a_former_employee_and_claims_their_cards_history(): void
    {
        \App\Models\Cardholder::create([
            'card_uid' => '47E34FD3', 'username' => 'Andreas Berger', 'user_date' => '2023-01-01',
        ]);
        UserLog::create([
            'employee_id' => null, 'card_uid' => '47E34FD3',
            'device_uid' => 'x', 'device_dep' => 'Buero', 'checkindate' => '2024-10-07',
            'timein' => '08:00:00', 'timeout' => '16:30:00', 'card_out' => 1,
        ]);

        $this->asAdmin()->postJson('/api/v1/admin/employees', [
            'name' => 'Andreas Berger', 'is_active' => false, 'cards' => ['47:e3:4f:d3'],
        ])
            ->assertCreated()
            ->assertJsonPath('employee.email', 'andreas.berger@ehemalig.invalid')
            ->assertJsonPath('employee.cards.0', '47E34FD3')
            ->assertJsonPath('employee.stampings', 1);

        $employee = Employee::where('name', 'Andreas Berger')->sole();
        $this->assertFalse($employee->is_active);
        $this->assertSame(1, \App\Models\Cardholder::where('card_uid', '47E34FD3')->count());
        $this->assertTrue(WorkDay::where('employee_id', $employee->id)->where('work_date', '2024-10-07')->exists());

        $this->asAdmin()->postJson('/api/v1/admin/employees', ['name' => 'Andreas Berger'])->assertStatus(409);
    }
}
