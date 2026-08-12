<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceLogTest extends TestCase
{
    use RefreshDatabase;

    private Company $own;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['laratrust.cache.enabled' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->own = Company::create(['name' => 'Own Co', 'is_active' => true, 'rate_per_hr' => 120]);
        $this->other = Company::create(['name' => 'Other Co', 'is_active' => true, 'rate_per_hr' => 200]);
    }

    private function user(string $role, Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    private function employee(Company $company, ?User $user = null, string $number = 'E-1'): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'company_id' => $company->id,
            'employee_no' => $number,
            'first_name' => 'Ada',
            'middle_name' => 'B',
            'last_name' => 'Lovelace',
        ]);
    }

    public function test_employee_check_in_always_creates_a_pending_log_for_today(): void
    {
        $user = $this->user('employee', $this->own);
        $employee = $this->employee($this->own, $user);

        $this->actingAs($user)->post('/attendance-logs/check-in', ['notes' => 'On site'])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'date' => today()->toDateString(),
            'status' => 'pending',
            'notes' => 'On site',
        ]);
    }

    public function test_employee_cannot_create_two_logs_for_the_same_day(): void
    {
        $user = $this->user('employee', $this->own);
        $this->employee($this->own, $user);

        $this->actingAs($user)->post('/attendance-logs/check-in');
        $this->actingAs($user)->post('/attendance-logs/check-in')->assertStatus(422);

        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_backend_computes_duration_from_time_in_and_time_out(): void
    {
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:15:00',
            'log_out_time' => '17:45:00',
            'status' => 'pending',
        ]);

        $this->assertSame(570, $log->duration_minutes);
        $this->assertSame('9:30', $log->duration);
    }

    public function test_company_admin_cannot_update_another_companys_log(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $otherLog = AttendanceLog::create([
            'employee_id' => $this->employee($this->other)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->putJson("/attendance-logs/{$otherLog->id}", [
            'employee_id' => $otherLog->employee_id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'approved',
        ])->assertForbidden();
    }

    public function test_company_admin_can_approve_a_pending_company_log(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->put("/attendance-logs/{$log->id}/approve")->assertSessionHas('success');
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
            'reject_reason' => null,
        ]);
        $this->assertNotNull($log->fresh()->approved_at);
    }

    public function test_approving_through_the_form_defaults_the_approver_to_the_acting_admin(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->put("/attendance-logs/{$log->id}", [
            'employee_id' => $log->employee_id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'approved',
        ])->assertSessionHas('success');

        $this->assertSame($admin->id, $log->fresh()->approved_by);
        $this->assertNotNull($log->fresh()->approved_at);
    }

    public function test_an_explicit_approver_and_timestamp_are_stored(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $other = $this->user('company_admin', $this->own);
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->put("/attendance-logs/{$log->id}", [
            'employee_id' => $log->employee_id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'approved',
            'approved_by' => $other->id,
            'approved_at' => '2026-08-12T09:30',
        ])->assertSessionHas('success');

        $log->refresh();
        $this->assertSame($other->id, $log->approved_by);
        $this->assertSame('2026-08-12 09:30:00', $log->approved_at->format('Y-m-d H:i:s'));
    }

    public function test_an_approver_from_another_company_is_rejected(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $outsider = $this->user('company_admin', $this->other);
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'status' => 'pending',
        ]);

        // Validation flashes to the session rather than returning 422 — see
        // shouldRenderJsonWhen in bootstrap/app.php.
        $this->actingAs($admin)->put("/attendance-logs/{$log->id}", [
            'employee_id' => $log->employee_id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'approved',
            'approved_by' => $outsider->id,
        ])->assertSessionHasErrors('approved_by');

        $this->assertNull($log->fresh()->approved_by);
    }

    public function test_rejecting_requires_a_reason_and_clears_the_approval(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $payload = [
            'employee_id' => $log->employee_id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'rejected',
        ];

        $this->actingAs($admin)->put("/attendance-logs/{$log->id}", $payload)
            ->assertSessionHasErrors('reject_reason');

        $this->actingAs($admin)->put("/attendance-logs/{$log->id}", [
            ...$payload,
            'reject_reason' => 'Times do not match the site log.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'status' => 'rejected',
            'approved_by' => null,
            'approved_at' => null,
            'reject_reason' => 'Times do not match the site log.',
        ]);
    }

    public function test_moving_a_log_back_to_pending_clears_every_approval_field(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $log = AttendanceLog::create([
            'employee_id' => $this->employee($this->own)->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'rejected',
            'reject_reason' => 'Wrong times',
        ]);

        $this->actingAs($admin)->put("/attendance-logs/{$log->id}", [
            'employee_id' => $log->employee_id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'pending',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->id,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'reject_reason' => null,
        ]);
    }

    public function test_employee_cannot_use_manager_create_endpoint(): void
    {
        $user = $this->user('employee', $this->own);
        $employee = $this->employee($this->own, $user);

        $this->actingAs($user)->postJson('/attendance-logs', [
            'employee_id' => $employee->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'status' => 'approved',
        ])->assertForbidden();
    }

    public function test_admin_cannot_access_attendance_logs(): void
    {
        $admin = $this->user('admin', $this->own);

        $this->actingAs($admin)->get('/attendance-logs')->assertForbidden();
    }

    public function test_company_admin_and_employee_can_access_attendance_logs(): void
    {
        $companyAdmin = $this->user('company_admin', $this->own);
        $employee = $this->user('employee', $this->own);
        $this->employee($this->own, $employee);

        $this->actingAs($companyAdmin)->get('/attendance-logs')->assertOk();
        $this->actingAs($employee)->get('/attendance-logs')->assertOk();
    }
}
