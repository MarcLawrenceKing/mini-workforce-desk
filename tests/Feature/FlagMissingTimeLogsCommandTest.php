<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlagMissingTimeLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_only_employees_without_a_log_today_and_is_safe_to_rerun(): void
    {
        $this->travelTo('2026-08-14 18:00:00');

        $company = Company::create([
            'name' => 'Test Company',
            'is_active' => true,
            'rate_per_hr' => 100,
        ]);

        $present = $this->employee($company, 'E-001');
        $missing = $this->employee($company, 'E-002');

        AttendanceLog::create([
            'employee_id' => $present->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'status' => 'pending',
        ]);
        AttendanceLog::create([
            'employee_id' => $missing->id,
            'date' => today()->subDay(),
            'log_in_time' => '08:00:00',
            'status' => 'approved',
        ]);

        $this->artisan('timelogs:flag-missing')
            ->expectsOutput('Flagged 1 employee(s) as missing for 2026-08-14.')
            ->assertSuccessful();

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $missing->id,
            'date' => '2026-08-14',
            'status' => 'missing',
        ]);
        $this->assertDatabaseMissing('attendance_logs', [
            'employee_id' => $present->id,
            'status' => 'missing',
        ]);

        $this->artisan('timelogs:flag-missing')
            ->expectsOutput('Flagged 0 employee(s) as missing for 2026-08-14.')
            ->assertSuccessful();

        $this->assertDatabaseCount('attendance_logs', 3);
    }

    private function employee(Company $company, string $number): Employee
    {
        return Employee::create([
            'company_id' => $company->id,
            'employee_no' => $number,
            'first_name' => 'Test',
            'middle_name' => 'M',
            'last_name' => $number,
        ]);
    }
}
