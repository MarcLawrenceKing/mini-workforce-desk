<?php

namespace Tests\Feature;

use App\Jobs\SendTimeLogApprovedNotification;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 9 — the JSON API. Covers the three things the web tests can't: bearer
 * token auth, the JSON error shapes (401/403/422), and the DELETE verb.
 */
class TimeLogApiTest extends TestCase
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

    private function user(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->own)->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    private function employee(?Company $company = null, ?User $user = null, string $number = 'E-1'): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'company_id' => ($company ?? $this->own)->id,
            'employee_no' => $number,
            'first_name' => 'Ada',
            'middle_name' => 'B',
            'last_name' => 'Lovelace',
        ]);
    }

    private function log(Employee $employee, array $attributes = []): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'log_in_time' => '08:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'pending',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Employee $employee, array $overrides = []): array
    {
        return [
            'employee_id' => $employee->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'pending',
            ...$overrides,
        ];
    }

    // --- Authentication ----------------------------------------------------

    public function test_an_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/time-logs')->assertUnauthorized();
    }

    public function test_a_garbage_bearer_token_is_rejected_with_401(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/time-logs')
            ->assertUnauthorized();
    }

    public function test_login_returns_a_usable_token(): void
    {
        $user = $this->user('company_admin');

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',   // UserFactory's default
            'device_name' => 'phpunit',
        ])->assertOk()->json('token');

        $this->assertNotEmpty($token);

        // The token, and nothing else, gets the caller in.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0', 'company_admin');
    }

    public function test_bad_credentials_return_422_not_401(): void
    {
        $user = $this->user('company_admin');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_disabled_account_is_refused_a_token(): void
    {
        $user = $this->user('company_admin');
        $user->update(['is_disabled' => true]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_token_belonging_to_a_disabled_user_is_refused_with_403(): void
    {
        // Disabled *after* the token was issued — the `active` middleware, not
        // the login check, is what stops this one.
        $user = $this->user('company_admin');
        Sanctum::actingAs($user);
        $user->update(['is_disabled' => true]);

        $this->getJson('/api/time-logs')->assertForbidden();
    }

    /**
     * Guards cache the user they resolved, and the container survives between
     * requests inside one test — unlike real HTTP, where every request is a
     * fresh process. Forgetting them makes the next call re-read the token.
     */
    private function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = $this->user('company_admin');
        $keep = $user->createToken('other-device')->plainTextToken;
        $revoke = $user->createToken('this-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$revoke}")
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->forgetAuth();
        $this->withHeader('Authorization', "Bearer {$revoke}")->getJson('/api/user')->assertUnauthorized();

        $this->forgetAuth();
        $this->withHeader('Authorization', "Bearer {$keep}")->getJson('/api/user')->assertOk();
    }

    // --- Authorisation -----------------------------------------------------

    public function test_admin_has_no_attendance_permission_and_gets_403(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/time-logs')->assertForbidden();
    }

    public function test_an_employee_sees_only_its_own_logs(): void
    {
        $user = $this->user('employee');
        $mine = $this->log($this->employee($this->own, $user, 'E-MINE'));
        $this->log($this->employee($this->own, null, 'E-THEIRS'));

        Sanctum::actingAs($user);

        $this->getJson('/api/time-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_an_employee_cannot_read_someone_elses_log(): void
    {
        $user = $this->user('employee');
        $this->employee($this->own, $user, 'E-MINE');
        $theirs = $this->log($this->employee($this->own, null, 'E-THEIRS'));

        Sanctum::actingAs($user);

        $this->getJson("/api/time-logs/{$theirs->id}")->assertForbidden();
    }

    public function test_an_employee_cannot_write(): void
    {
        $user = $this->user('employee');
        $employee = $this->employee($this->own, $user);
        $log = $this->log($employee, ['date' => today()->subDay()]);

        Sanctum::actingAs($user);

        $this->postJson('/api/time-logs', $this->payload($employee))->assertForbidden();
        $this->putJson("/api/time-logs/{$log->id}", $this->payload($employee))->assertForbidden();
        $this->putJson("/api/time-logs/{$log->id}/approve")->assertForbidden();
        $this->deleteJson("/api/time-logs/{$log->id}")->assertForbidden();
    }

    public function test_a_company_admin_cannot_touch_another_companys_log(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $outside = $this->log($this->employee($this->other));

        $this->getJson("/api/time-logs/{$outside->id}")->assertForbidden();
        $this->putJson("/api/time-logs/{$outside->id}", $this->payload($outside->employee))->assertForbidden();
        $this->deleteJson("/api/time-logs/{$outside->id}")->assertForbidden();
    }

    public function test_the_list_never_leaks_another_companys_rows(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $mine = $this->log($this->employee($this->own));
        $this->log($this->employee($this->other));

        $this->getJson('/api/time-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    // --- CRUD --------------------------------------------------------------

    public function test_index_returns_the_documented_shape(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $this->log($this->employee());

        $this->getJson('/api/time-logs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'employee_id', 'employee' => ['employee_no', 'full_name', 'company'], 'date', 'time_in', 'time_out', 'notes', 'status', 'duration', 'duration_minutes']],
                'meta' => ['month', 'total', 'worked_minutes'],
            ])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.worked_minutes', 540);
    }

    public function test_index_filters_by_month_and_status(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $employee = $this->employee();
        $this->log($employee, ['date' => '2026-07-15', 'status' => 'approved']);
        $this->log($employee, ['date' => '2026-08-05', 'status' => 'pending']);
        $this->log($employee, ['date' => '2026-08-06', 'status' => 'approved']);

        $this->getJson('/api/time-logs?month=2026-07')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/time-logs?month=2026-08')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/time-logs?month=2026-08&status=approved')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_store_creates_a_log_and_returns_201(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $employee = $this->employee();

        $this->postJson('/api/time-logs', $this->payload($employee, ['notes' => 'Via the API']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.duration', '9:00');

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'log_in_time' => '08:00',
            'notes' => 'Via the API',
        ]);
    }

    public function test_show_returns_one_log(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $log = $this->log($this->employee());

        $this->getJson("/api/time-logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $log->id);
    }

    public function test_update_changes_the_log(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $log = $this->log($this->employee());

        $this->putJson("/api/time-logs/{$log->id}", $this->payload($log->employee, [
            'time_out' => '19:30',
            'notes' => 'Overtime',
        ]))->assertOk()->assertJsonPath('data.time_out', '19:30');

        $this->assertSame('Overtime', $log->fresh()->notes);
    }

    public function test_destroy_removes_the_row_and_returns_204(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $log = $this->log($this->employee());

        $this->deleteJson("/api/time-logs/{$log->id}")->assertNoContent();

        $this->assertDatabaseMissing('attendance_logs', ['id' => $log->id]);
    }

    public function test_a_missing_log_is_a_404(): void
    {
        Sanctum::actingAs($this->user('company_admin'));

        $this->getJson('/api/time-logs/99999')->assertNotFound();
    }

    // --- Validation (422) --------------------------------------------------

    public function test_time_out_before_time_in_is_a_422(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $employee = $this->employee();

        $this->postJson('/api/time-logs', $this->payload($employee, [
            'time_in' => '17:00',
            'time_out' => '08:00',
        ]))->assertStatus(422)->assertJsonValidationErrors('time_out');
    }

    public function test_a_duplicate_date_for_the_same_employee_is_a_422(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $employee = $this->employee();
        $this->log($employee);

        $this->postJson('/api/time-logs', $this->payload($employee))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_an_employee_from_another_company_is_a_422(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $outsider = $this->employee($this->other);

        $this->postJson('/api/time-logs', $this->payload($outsider))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_rejecting_without_a_reason_is_a_422(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $employee = $this->employee();

        $this->postJson('/api/time-logs', $this->payload($employee, ['status' => 'rejected']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reject_reason');
    }

    public function test_a_log_cannot_be_moved_to_another_employee(): void
    {
        Sanctum::actingAs($this->user('company_admin'));
        $log = $this->log($this->employee($this->own, null, 'E-1'));
        $someoneElse = $this->employee($this->own, null, 'E-2');

        $this->putJson("/api/time-logs/{$log->id}", $this->payload($someoneElse))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    // --- Approval + the Task 8 queue --------------------------------------

    public function test_approve_sets_the_approver_and_queues_the_notification(): void
    {
        Queue::fake();

        $admin = $this->user('company_admin');
        Sanctum::actingAs($admin);
        $log = $this->log($this->employee());

        $this->putJson("/api/time-logs/{$log->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $admin->id);

        $this->assertNotNull($log->fresh()->approved_at);
        Queue::assertPushed(SendTimeLogApprovedNotification::class);
    }

    public function test_an_already_approved_log_cannot_be_approved_again(): void
    {
        $admin = $this->user('company_admin');
        Sanctum::actingAs($admin);
        $log = $this->log($this->employee(), ['status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()]);

        $this->putJson("/api/time-logs/{$log->id}/approve")->assertStatus(422);
    }

    public function test_updating_without_a_status_change_does_not_renotify(): void
    {
        Queue::fake();

        $admin = $this->user('company_admin');
        Sanctum::actingAs($admin);
        $log = $this->log($this->employee(), ['status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()]);

        $this->putJson("/api/time-logs/{$log->id}", $this->payload($log->employee, [
            'status' => 'approved',
            'notes' => 'Corrected a typo',
        ]))->assertOk();

        Queue::assertNothingPushed();
    }
}
