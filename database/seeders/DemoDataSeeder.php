<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $companyOne = Company::updateOrCreate(
            ['name' => 'Company One'],
            ['is_active' => true, 'rate_per_hr' => 100.00],
        );
        $companyTwo = Company::updateOrCreate(
            ['name' => 'Company Two'],
            ['is_active' => true, 'rate_per_hr' => 150.00],
        );

        // Only ever one admin. If you registered one through /register this is skipped,
        // so the seeder can't quietly create a second and defeat the bootstrap rule.
        if (User::adminExists()) {
            $this->command?->warn('An admin already exists — skipping admin@test.com.');
        } else {
            $this->makeUser('admin@test.com', 'admin', 'System Admin', null, 'admin');
            $this->command?->info('Seeded admin@test.com (no admin existed).');
        }

        // Company One
        $this->makeUser('admin@company1.com', 'company1admin', 'Company One Admin', $companyOne, 'company_admin');

        $employeeUser = $this->makeUser(
            'employee@company1.com',
            'company1employee',
            'Company One Employee',
            $companyOne,
            'employee',
        );

        $companyOneEmployee = Employee::updateOrCreate(
            ['user_id' => $employeeUser->id],
            [
                'company_id' => $companyOne->id,
                'employee_no' => 'C1-0001',
                'first_name' => 'Ada',
                'middle_name' => 'B',   // NOT NULL in the migration
                'last_name' => 'Lovelace',
            ],
        );

        // Proves EnsureAccountIsActive without having to edit a row by hand.
        $disabled = $this->makeUser(
            'disabled@company1.com',
            'company1disabled',
            'Disabled Employee',
            $companyOne,
            'employee',
        );
        $disabled->update(['is_disabled' => true]);

        // A second company so "company_admin cannot manage other companies" is testable.
        $this->makeUser('admin@company2.com', 'company2admin', 'Company Two Admin', $companyTwo, 'company_admin');

        $companyTwoEmployeeUser = $this->makeUser(
            'employee@company2.com',
            'company2employee',
            'Company Two Employee',
            $companyTwo,
            'employee',
        );

        $companyTwoEmployee = Employee::updateOrCreate(
            [
                'company_id' => $companyTwo->id,
                'employee_no' => 'C2-0001',
            ],
            [
                'user_id' => $companyTwoEmployeeUser->id,
                'first_name' => 'Grace',
                'middle_name' => 'B',
                'last_name' => 'Hopper',
            ],
        );

        $this->seedAttendanceLogs($companyOneEmployee);
        $this->seedAttendanceLogs($companyTwoEmployee);
    }

    private function seedAttendanceLogs(Employee $employee): void
    {
        $logs = [
            [
                'date' => '2026-08-10',
                'log_in_time' => '08:00:00',
                'log_out_time' => '17:00:00',
                'notes' => 'Regular work day',
                'status' => 'approved',
            ],
            [
                'date' => '2026-08-11',
                'log_in_time' => '08:30:00',
                'log_out_time' => '17:30:00',
                'notes' => 'Regular work day',
                'status' => 'pending',
            ],
        ];

        foreach ($logs as $log) {
            AttendanceLog::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'date' => $log['date'],
                ],
                $log,
            );
        }
    }

    private function makeUser(
        string $email,
        string $username,
        string $name,
        ?Company $company,
        string $role,
    ): User {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => $username,
                'company_id' => $company?->id,
                'password' => 'password',   // hashed by the model cast
                'is_disabled' => false,
            ],
        );

        $user->syncRoles([$role]);

        return $user;
    }
}
