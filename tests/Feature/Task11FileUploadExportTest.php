<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Task11FileUploadExportTest extends TestCase
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

    public function test_an_employee_photo_is_uploaded_to_the_public_disk_and_exposed_to_both_admin_roles(): void
    {
        Storage::fake('public');
        $companyAdmin = $this->user('company_admin', $this->own);
        $photo = UploadedFile::fake()->createWithContent(
            'avatar.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );

        $this->actingAs($companyAdmin)->post('/employees', [
            ...$this->employeePayload(),
            'photo' => $photo,
        ])->assertSessionHas('success');

        $employee = Employee::where('employee_no', 'E-001')->firstOrFail();
        $storedPath = $employee->getRawOriginal('photo_url');

        $this->assertNotNull($storedPath);
        Storage::disk('public')->assertExists($storedPath);

        $this->actingAs($companyAdmin)->get('/employees')
            ->assertInertia(fn ($page) => $page->where(
                'employees.0.photo_url',
                fn ($url) => str_ends_with($url, '/storage/'.$storedPath),
            ));

        $this->actingAs($this->user('admin'))->get('/employees')
            ->assertInertia(fn ($page) => $page->where(
                'employees.0.photo_url',
                fn ($url) => str_ends_with($url, '/storage/'.$storedPath),
            ));
    }

    public function test_api_csv_export_contains_only_the_authenticated_companys_logs(): void
    {
        $admin = $this->user('company_admin', $this->own);
        $ownEmployee = $this->employee($this->own, 'OWN-1');
        $otherEmployee = $this->employee($this->other, 'OTHER-1');

        $this->log($ownEmployee, '2026-08-10', 'Own company note');
        $this->log($otherEmployee, '2026-08-10', 'Other company secret');

        Sanctum::actingAs($admin);

        $response = $this->get('/api/time-logs/export?month=2026-08&company_id='.$this->other->id)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('OWN-1', $csv);
        $this->assertStringContainsString('Own company note', $csv);
        $this->assertStringNotContainsString('OTHER-1', $csv);
        $this->assertStringNotContainsString('Other company secret', $csv);
    }

    public function test_employee_and_global_admin_roles_cannot_export_time_logs(): void
    {
        Sanctum::actingAs($this->user('employee', $this->own));
        $this->getJson('/api/time-logs/export')->assertForbidden();

        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/time-logs/export')->assertForbidden();

        $this->actingAs($this->user('employee', $this->own))
            ->getJson('/attendance-logs/export')
            ->assertForbidden();
    }

    private function user(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    /** @return array<string, string> */
    private function employeePayload(): array
    {
        return [
            'employee_no' => 'E-001',
            'first_name' => 'Ada',
            'middle_name' => 'B',
            'last_name' => 'Lovelace',
        ];
    }

    private function employee(Company $company, string $number): Employee
    {
        return Employee::create([
            'company_id' => $company->id,
            'employee_no' => $number,
            'first_name' => 'Ada',
            'middle_name' => 'B',
            'last_name' => 'Lovelace',
        ]);
    }

    private function log(Employee $employee, string $date, string $notes): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'log_in_time' => '08:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'approved',
            'notes' => $notes,
        ]);
    }
}
