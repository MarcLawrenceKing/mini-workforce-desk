<?php

namespace Tests\Feature;

use App\Jobs\SendTimeLogApprovedNotification;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RedisCacheQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['laratrust.cache.enabled' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    public function test_dashboard_counts_are_cached_and_dropped_when_a_company_is_added(): void
    {
        $admin = $this->user('admin');
        Company::create(['name' => 'First', 'is_active' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->assertSame(
            1,
            Cache::tags(['dashboard'])->get('dashboard:kpis:any:any')['companies'],
        );

        // Creating a company must invalidate the cached counts.
        Company::create(['name' => 'Second', 'is_active' => true]);

        $this->assertNull(Cache::tags(['dashboard'])->get('dashboard:kpis:any:any'));

        $this->actingAs($admin)->get('/dashboard')
            ->assertInertia(fn($page) => $page->where('kpis.companies', 2));
    }

    public function test_approving_a_log_queues_the_notification_job(): void
    {
        Queue::fake();

        $company = Company::create(['name' => 'Own Co', 'is_active' => true, 'rate_per_hr' => 100]);
        $admin = $this->user('company_admin', $company);
        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_no' => 'E-1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $log = AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => today()->toDateString(),
            'log_in_time' => '09:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->put("/attendance-logs/{$log->id}/approve")
            ->assertSessionHas('success');

        Queue::assertPushed(
            SendTimeLogApprovedNotification::class,
            fn($job) => $job->attendanceLog->id === $log->id && $job->decision === 'approved',
        );
    }
}
