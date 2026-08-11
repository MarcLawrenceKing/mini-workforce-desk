<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $companyOne = Company::updateOrCreate(['name' => 'Company One'], ['is_active' => true]);
        $companyTwo = Company::updateOrCreate(['name' => 'Company Two'], ['is_active' => true]);

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

        Employee::updateOrCreate(
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
