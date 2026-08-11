<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Task 5: company-scoped employee CRUD and the soft delete behind it. */
class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    private Company $own;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['laratrust.cache.enabled' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->own = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $this->other = Company::create(['name' => 'Other Co', 'is_active' => true]);
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    private function employeeFor(Company $company, string $employeeNo = 'E-0001'): Employee
    {
        return Employee::create([
            'company_id' => $company->id,
            'employee_no' => $employeeNo,
            'first_name' => 'Ada',
            'middle_name' => 'B',
            'last_name' => 'Lovelace',
        ]);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return [
            'employee_no' => 'E-0002',
            'first_name' => 'Grace',
            'middle_name' => 'M',
            'last_name' => 'Hopper',
            ...$overrides,
        ];
    }

    /* ---- create --------------------------------------------------------- */

    public function test_company_admin_creates_an_employee_inside_its_own_company(): void
    {
        $admin = $this->userWithRole('company_admin', $this->own);

        $this->actingAs($admin)
            ->post('/employees', $this->payload())
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'E-0002',
            'company_id' => $this->own->id,
        ]);
    }

    public function test_company_admin_cannot_create_into_another_company(): void
    {
        $admin = $this->userWithRole('company_admin', $this->own);

        // The posted company_id is ignored — the viewer's own company wins.
        $this->actingAs($admin)
            ->post('/employees', $this->payload(['company_id' => $this->other->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'E-0002',
            'company_id' => $this->own->id,
        ]);
    }

    public function test_an_admin_has_to_pick_a_company(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post('/employees', $this->payload())
            ->assertSessionHasErrors('company_id');

        $this->actingAs($admin)
            ->post('/employees', $this->payload(['company_id' => $this->other->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'E-0002',
            'company_id' => $this->other->id,
        ]);
    }

    public function test_employee_no_is_unique_per_company_but_reusable_across_them(): void
    {
        $this->employeeFor($this->own, 'E-0001');
        $this->employeeFor($this->other, 'E-0001');   // fine: different company

        $this->actingAs($this->userWithRole('company_admin', $this->own))
            ->post('/employees', $this->payload(['employee_no' => 'E-0001']))
            ->assertSessionHasErrors('employee_no');

        $this->assertSame(2, Employee::count());
    }

    /* ---- scoping -------------------------------------------------------- */

    public function test_company_admin_only_sees_its_own_employees(): void
    {
        $this->employeeFor($this->own, 'OWN-1');
        $this->employeeFor($this->other, 'OTHER-1');

        $this->actingAs($this->userWithRole('company_admin', $this->own))
            ->get('/employees')
            ->assertInertia(fn ($page) => $page
                ->component('Employees/Index')
                ->has('employees', 1)
                ->where('employees.0.employee_no', 'OWN-1'));
    }

    public function test_company_admin_cannot_touch_another_companys_employee(): void
    {
        $foreign = $this->employeeFor($this->other);
        $admin = $this->userWithRole('company_admin', $this->own);

        $this->actingAs($admin)
            ->putJson("/employees/{$foreign->id}", $this->payload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson("/employees/{$foreign->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($foreign);
    }

    /* ---- soft delete ---------------------------------------------------- */

    public function test_deleting_an_employee_is_a_soft_delete(): void
    {
        $employee = $this->employeeFor($this->own);
        $admin = $this->userWithRole('company_admin', $this->own);

        $this->actingAs($admin)
            ->delete("/employees/{$employee->id}")
            ->assertSessionHas('success');

        $this->assertSoftDeleted($employee);

        // Gone from the default list...
        $this->actingAs($admin)
            ->get('/employees')
            ->assertInertia(fn ($page) => $page->has('employees', 0));

        // ...but reachable through the toggle.
        $this->actingAs($admin)
            ->get('/employees?trashed=1')
            ->assertInertia(fn ($page) => $page
                ->has('employees', 1)
                ->where('trashed', true)
                ->where('employees.0.deleted_at', fn ($value) => $value !== null));
    }

    public function test_a_deleted_employee_can_be_restored(): void
    {
        $employee = $this->employeeFor($this->own);
        $employee->delete();

        $this->actingAs($this->userWithRole('company_admin', $this->own))
            ->put("/employees/{$employee->id}/restore")
            ->assertSessionHas('success');

        $this->assertNotSoftDeleted($employee);
    }

    public function test_an_employee_role_cannot_delete_anything(): void
    {
        $employee = $this->employeeFor($this->own);

        $this->actingAs($this->userWithRole('employee', $this->own))
            ->deleteJson("/employees/{$employee->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($employee);
    }

    /* ---- company delete guard -------------------------------------------- */

    public function test_a_company_holding_employees_cannot_be_deleted(): void
    {
        $employee = $this->employeeFor($this->own);
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->delete("/companies/{$this->own->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('companies', ['id' => $this->own->id]);

        // Still blocked once the employee is only soft-deleted: the row, and so
        // the foreign key, is still there.
        $employee->delete();

        $this->actingAs($admin)
            ->delete("/companies/{$this->own->id}")
            ->assertSessionHas('error');

        $employee->forceDelete();

        $this->actingAs($admin)
            ->delete("/companies/{$this->own->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('companies', ['id' => $this->own->id]);
    }

    /* ---- linked account -------------------------------------------------- */

    public function test_only_unlinked_accounts_are_offered_for_linking(): void
    {
        $admin = $this->userWithRole('company_admin', $this->own);
        $linked = $this->userWithRole('employee', $this->own);
        $free = $this->userWithRole('employee', $this->own);

        $this->employeeFor($this->own)->update(['user_id' => $linked->id]);

        $this->actingAs($admin)
            ->get('/employees')
            ->assertInertia(fn ($page) => $page
                ->where('linkableUsers', fn ($users) => $users
                    ->pluck('id')
                    ->contains($free->id)
                    && ! $users->pluck('id')->contains($linked->id)));
    }

    public function test_an_account_cannot_be_linked_to_two_employees(): void
    {
        $admin = $this->userWithRole('company_admin', $this->own);
        $account = $this->userWithRole('employee', $this->own);

        $this->employeeFor($this->own)->update(['user_id' => $account->id]);

        $this->actingAs($admin)
            ->post('/employees', $this->payload(['user_id' => $account->id]))
            ->assertSessionHasErrors('user_id');
    }
}
