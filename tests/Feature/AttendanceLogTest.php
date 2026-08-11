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
        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id, 'status' => 'approved']);
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
}
