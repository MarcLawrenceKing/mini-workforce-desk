<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['laratrust.cache.enabled' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->addRole($role);

        return $user->fresh();
    }

    public function test_only_admins_can_open_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $this->actingAs($this->userWithRole('employee'))
            ->get('/dashboard')
            ->assertRedirect('/my-account');

        $this->actingAs($this->userWithRole('admin'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('AdminDashboard'));
    }

    public function test_the_date_range_filters_employee_and_company_counts_inclusively(): void
    {
        $admin = $this->userWithRole('admin');

        $insideCompany = Company::create(['name' => 'Inside', 'is_active' => true]);
        $insideCompany->forceFill(['created_at' => '2026-08-10 23:59:59'])->save();

        $outsideCompany = Company::create(['name' => 'Outside', 'is_active' => true]);
        $outsideCompany->forceFill(['created_at' => '2026-08-11 00:00:00'])->save();

        $insideEmployee = Employee::create([
            'company_id' => $insideCompany->id,
            'employee_no' => 'IN-001',
            'first_name' => 'Inside',
            'last_name' => 'Employee',
        ]);
        $insideEmployee->forceFill(['created_at' => '2026-08-10 00:00:00'])->save();

        $outsideEmployee = Employee::create([
            'company_id' => $outsideCompany->id,
            'employee_no' => 'OUT-001',
            'first_name' => 'Outside',
            'last_name' => 'Employee',
        ]);
        $outsideEmployee->forceFill(['created_at' => '2026-08-11 00:00:00'])->save();

        User::factory()->count(2)->create();

        $this->actingAs($admin)
            ->get('/dashboard?from=2026-08-10&to=2026-08-10')
            ->assertInertia(fn ($page) => $page
                ->where('kpis.employees', 1)
                ->where('kpis.companies', 1)
                ->where('kpis.users', 3)
                ->where('filters.from', '2026-08-10')
                ->where('filters.to', '2026-08-10'));
    }

    public function test_the_dashboard_rejects_an_inverted_date_range(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->from('/dashboard')
            ->get('/dashboard?from=2026-08-11&to=2026-08-10')
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('to');
    }

    public function test_either_date_bound_can_be_used_on_its_own(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get('/dashboard?from=2026-08-10')->assertOk();
        $this->actingAs($admin)->get('/dashboard?to=2026-08-10')->assertOk();
    }
}
