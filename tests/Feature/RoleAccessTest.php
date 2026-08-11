<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laratrust caches a user's roles/permissions; caching across in-test
        // role changes makes these assertions flaky.
        config(['laratrust.cache.enabled' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    /* ---- Task 3: authentication ---------------------------------------- */

    public function test_guests_are_redirected_from_private_routes(): void
    {
        $this->get('/my-account')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
        $this->get('/companies')->assertRedirect('/login');
        $this->get('/employees')->assertRedirect('/login');
    }

    public function test_the_public_routes_stay_public(): void
    {
        $this->get('/')->assertOk();
        $this->get('/task2')->assertOk();   // left unauthenticated by design
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get('/login')
            ->assertRedirect('/my-account');
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = $this->userWithRole('employee');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/my-account');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    /* ---- One-time admin registration ----------------------------------- */

    public function test_registration_creates_the_first_admin(): void
    {
        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'First Admin',
            'username' => 'firstadmin',
            'email' => 'first@admin.test',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect('/my-account');

        $admin = User::where('email', 'first@admin.test')->firstOrFail();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNull($admin->company_id);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_registration_closes_once_an_admin_exists(): void
    {
        $this->userWithRole('admin');

        $this->get('/register')
            ->assertRedirect('/login')
            ->assertSessionHas('error');

        $this->post('/register', [
            'name' => 'Second Admin',
            'username' => 'secondadmin',
            'email' => 'second@admin.test',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'second@admin.test']);
    }

    public function test_the_login_page_hides_the_register_link_once_an_admin_exists(): void
    {
        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('canRegister', true));

        $this->userWithRole('admin');

        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    /* ---- Task 4: roles, permissions, scoping --------------------------- */

    public function test_employee_is_redirected_from_the_employees_page(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get('/employees')
            ->assertRedirect('/my-account')
            ->assertSessionHas('error');
    }

    public function test_employee_gets_a_hard_403_when_the_client_wants_json(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->getJson('/employees')
            ->assertForbidden();
    }

    public function test_employee_reaches_its_own_record_through_my_account(): void
    {
        $company = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $user = $this->userWithRole('employee', $company);

        $user->employee()->create([
            'company_id' => $company->id,
            'employee_no' => 'C1-0001',
            'first_name' => 'Ada',
            'middle_name' => 'B',
            'last_name' => 'Lovelace',
        ]);

        $this->actingAs($user)
            ->get('/my-account')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MyAccount')
                ->where('employee.employee_no', 'C1-0001')
                ->where('employee.full_name', 'Ada B Lovelace'));
    }

    public function test_employee_can_open_its_placeholder_pages(): void
    {
        $employee = $this->userWithRole('employee');

        $this->actingAs($employee)->get('/attendance-logs')->assertOk();
        $this->actingAs($employee)->get('/requests')->assertOk();
    }

    public function test_company_admin_can_reach_employees_but_not_create_companies(): void
    {
        $company = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $admin = $this->userWithRole('company_admin', $company);

        $this->actingAs($admin)->get('/employees')->assertOk();

        $this->actingAs($admin)
            ->postJson('/companies', ['name' => 'Sneaky Co'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_update_another_company(): void
    {
        $own = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $other = Company::create(['name' => 'Other Co', 'is_active' => true]);

        $admin = $this->userWithRole('company_admin', $own);

        $this->actingAs($admin)
            ->putJson("/companies/{$other->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Other Co', $other->fresh()->name);

        $this->actingAs($admin)
            ->put("/companies/{$own->id}", ['name' => 'Own Co Renamed'])
            ->assertSessionHas('success');
    }

    public function test_company_admin_only_sees_its_own_company(): void
    {
        $own = Company::create(['name' => 'Own Co', 'is_active' => true]);
        Company::create(['name' => 'Other Co', 'is_active' => true]);

        $this->actingAs($this->userWithRole('company_admin', $own))
            ->get('/companies')
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Index')
                ->has('companies', 1)
                ->where('companies.0.name', 'Own Co'));
    }

    public function test_company_admin_cannot_delete_a_company(): void
    {
        $own = Company::create(['name' => 'Own Co', 'is_active' => true]);

        $this->actingAs($this->userWithRole('company_admin', $own))
            ->deleteJson("/companies/{$own->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('companies', ['id' => $own->id]);
    }

    public function test_admin_can_manage_every_company(): void
    {
        Company::create(['name' => 'Own Co', 'is_active' => true]);
        $other = Company::create(['name' => 'Other Co', 'is_active' => true]);

        $this->actingAs($this->userWithRole('admin'))
            ->put("/companies/{$other->id}", ['name' => 'Renamed By Admin'])
            ->assertSessionHas('success');

        $this->assertSame('Renamed By Admin', $other->fresh()->name);
    }

    public function test_admin_sees_every_company(): void
    {
        Company::create(['name' => 'Own Co', 'is_active' => true]);
        Company::create(['name' => 'Other Co', 'is_active' => true]);

        $this->actingAs($this->userWithRole('admin'))
            ->get('/companies')
            ->assertInertia(fn ($page) => $page->has('companies', 2));
    }

    public function test_the_admin_role_is_never_assignable_from_the_users_page(): void
    {
        $company = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('employee', $company);

        $this->actingAs($admin)
            ->get('/users')
            ->assertInertia(fn ($page) => $page->where('assignableRoles', ['company_admin', 'employee']));

        // Validation redirects back rather than returning 422: shouldRenderJsonWhen
        // is scoped to api/*, which is what makes Inertia's useForm().errors work.
        $this->actingAs($admin)->put("/users/{$target->id}", [
            'name' => 'Renamed Person',
            'email' => 'renamed@example.test',
            'username' => 'renamed-person',
            'role' => 'admin',
        ])->assertSessionHasErrors('role');

        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_the_administrator_account_cannot_be_edited_from_the_users_page(): void
    {
        // syncRoles() there would strip the only admin's role and lock everyone out.
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->putJson("/users/{$admin->id}", [
            'name' => 'Renamed Admin',
            'email' => 'renamed@example.test',
            'username' => 'renamed-admin',
            'role' => 'employee',
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    /* ---- EnsureAccountIsActive ----------------------------------------- */

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = $this->userWithRole('employee');
        $user->update(['is_disabled' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_disabled_user_is_kicked_out_mid_session(): void
    {
        $user = $this->userWithRole('employee');

        $this->actingAs($user)->get('/my-account')->assertOk();

        $user->update(['is_disabled' => true]);

        $this->actingAs($user)->get('/my-account')->assertRedirect('/login');
        $this->assertGuest();
    }

    /* ---- Inertia shared data ------------------------------------------- */

    public function test_shared_inertia_props_expose_roles_and_permissions(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/my-account')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.roles.0', 'admin')
                ->where('auth.user.permissions', fn ($permissions) => in_array('companies.create', $permissions->all(), true)));
    }

    public function test_employee_permissions_exclude_the_employees_list(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get('/my-account')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.permissions', fn ($permissions) => ! in_array('employees.view', $permissions->all(), true)));
    }
}
