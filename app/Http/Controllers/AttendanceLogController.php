<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NotifiesTimeLogDecision;
use App\Http\Requests\StoreTimeLogRequest;
use App\Http\Requests\TimeLogRequest;
use App\Http\Requests\UpdateTimeLogRequest;
use App\Http\Resources\TimeLogResource;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Jobs\SendTimeLogApprovedNotification;



class AttendanceLogController extends Controller
{
    use NotifiesTimeLogDecision;

    private const STATUSES = TimeLogRequest::STATUSES;

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $isManager = $viewer->hasRole('company_admin');
        $month = $this->month($request->string('month')->toString());

        if ($isManager) {
            $logs = AttendanceLog::query()
                ->visibleTo($viewer)
                ->whereBetween('date', [$month->startOfMonth(), $month->endOfMonth()])
                ->with(['employee:id,company_id,employee_no,first_name,middle_name,last_name', 'employee.company:id,name', 'approver:id,name'])
                ->latest('date')
                ->latest('log_in_time')
                ->get()
                ->map(fn(AttendanceLog $log) => $this->logPayload($log));

            return Inertia::render('AttendanceLogs/AdminIndex', [
                'logs' => $logs,
                'month' => $month->format('Y-m'),
                'employees' => Employee::query()
                    ->visibleTo($viewer)
                    ->orderBy('last_name')
                    ->get()
                    ->map(fn(Employee $employee) => [
                        'id' => $employee->id,
                        'label' => "{$employee->employee_no} — {$employee->full_name}",
                    ]),
                'statuses' => self::STATUSES,
                'approvers' => $this->approvers($viewer)
                    ->map(fn(User $user) => ['id' => $user->id, 'label' => $user->name]),
            ]);
        }

        $employee = $viewer->employee;
        $logs = $employee
            ? $employee->attendanceLogs()
            ->whereBetween('date', [$month->startOfMonth(), $month->endOfMonth()])
            ->with('approver:id,name')
            ->orderByDesc('date')
            ->get()
            : collect();

        $workedMinutes = $logs->sum(fn(AttendanceLog $log) => $log->duration_minutes);
        $todayLog = $employee?->attendanceLogs()->with('approver:id,name')->whereDate('date', today())->first();

        return Inertia::render('AttendanceLogs/EmployeeIndex', [
            'logs' => $logs->map(fn(AttendanceLog $log) => $this->logPayload($log)),
            'month' => $month->format('Y-m'),
            'today' => today()->toDateString(),
            'todayLog' => $todayLog ? $this->logPayload($todayLog) : null,
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
            ] : null,
            'summary' => [
                'worked_minutes' => $workedMinutes,
                'rate_per_hr' => (float) ($viewer->company?->rate_per_hr ?? 0),
                'expected_salary' => round(($workedMinutes / 60) * (float) ($viewer->company?->rate_per_hr ?? 0), 2),
            ],
        ]);
    }

    // Validation, scoping and the column mapping all live in the form requests
    // now, so the API (Task 9) enforces byte-for-byte the same rules.
    public function store(StoreTimeLogRequest $request): RedirectResponse
    {
        AttendanceLog::create($request->toAttributes());

        return back()->with('success', 'Attendance log created.');
    }

    public function update(UpdateTimeLogRequest $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $previousStatus = $attendanceLog->status;

        $attendanceLog->update($request->toAttributes());
        $this->notifyOnDecision($attendanceLog, $previousStatus);

        return back()->with('success', 'Attendance log updated.');
    }

    public function approve(Request $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $this->assertManager($request);
        $this->assertLogInScope($request, $attendanceLog);

        abort_unless($attendanceLog->status === 'pending', 422, 'Only pending logs can be approved.');
        $attendanceLog->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'reject_reason' => null,
        ]);

        // Queued, not run inline — the admin gets their redirect immediately.
        SendTimeLogApprovedNotification::dispatch($attendanceLog, 'approved');

        return back()->with('success', 'Attendance log approved.');
    }

    public function checkIn(Request $request): RedirectResponse
    {
        $employee = $this->ownEmployee($request);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        abort_if($employee->attendanceLogs()->whereDate('date', today())->exists(), 422, 'You already have an attendance log for today.');

        $employee->attendanceLogs()->create([
            'date' => today()->toDateString(),
            'log_in_time' => now()->format('H:i:s'),
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        return back()->with('success', 'Time in recorded.');
    }

    public function checkOut(Request $request): RedirectResponse
    {
        $employee = $this->ownEmployee($request);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $log = $employee->attendanceLogs()->whereDate('date', today())->first();

        abort_unless($log && $log->log_in_time, 422, 'Log in before logging out.');
        abort_if($log->log_out_time, 422, 'You already logged out today.');
        abort_unless($log->status === 'pending', 422, 'Only pending attendance can be updated.');

        $log->update([
            'log_out_time' => now()->format('H:i:s'),
            'notes' => $validated['notes'] ?? $log->notes,
        ]);

        return back()->with('success', 'Time out recorded.');
    }

    /**
     * The company_admins a log may be signed off by — the approver dropdown, and
     * the whitelist the submitted approved_by is checked against.
     *
     * @return Collection<int, User>
     */
    private function approvers(User $viewer): Collection
    {
        return User::query()
            ->visibleTo($viewer)
            ->where('is_disabled', false)
            ->whereHas('roles', fn(Builder $roles) => $roles->where('name', 'company_admin'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function month(string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('!Y-m', $value) : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    /**
     * The Inertia pages and the JSON API share one serializer, so a field added
     * for the API shows up on the pages too.
     *
     * @return array<string, mixed>
     */
    private function logPayload(AttendanceLog $log): array
    {
        return TimeLogResource::make($log)->resolve();
    }

    private function assertManager(Request $request): void
    {
        abort_unless($request->user()->hasRole('company_admin'), 403);
    }

    private function ownEmployee(Request $request): Employee
    {
        abort_unless($request->user()->hasRole('employee'), 403);
        abort_unless($request->user()->employee, 422, 'Your account is not linked to an employee record.');

        return $request->user()->employee;
    }

    private function assertLogInScope(Request $request, AttendanceLog $log): void
    {
        abort_unless(AttendanceLog::query()->visibleTo($request->user())->whereKey($log->id)->exists(), 403);
    }
}
