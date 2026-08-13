<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Jobs\SendTimeLogApprovedNotification;



class AttendanceLogController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

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

    public function store(Request $request): RedirectResponse
    {
        $this->assertManager($request);
        $employee = $this->employeeInScope($request, $request->integer('employee_id'));

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => [
                'required',
                'date',
                Rule::unique('attendance_logs', 'date')->where('employee_id', $employee->id),
            ],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(self::STATUSES)],
            ...$this->approvalRules($request),
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => $validated['date'],
            'log_in_time' => $validated['time_in'],
            'log_out_time' => $validated['time_out'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
            ...$this->approvalAttributes($request, $validated),
        ]);

        return back()->with('success', 'Attendance log created.');
    }

    public function update(Request $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $this->assertManager($request);
        $this->assertLogInScope($request, $attendanceLog);

        $previousStatus = $attendanceLog->status;

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => [
                'required',
                'date',
                Rule::unique('attendance_logs', 'date')
                    ->where('employee_id', $attendanceLog->employee_id)
                    ->ignore($attendanceLog->id),
            ],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(self::STATUSES)],
            ...$this->approvalRules($request),
        ]);

        abort_unless((int) $validated['employee_id'] === $attendanceLog->employee_id, 422, 'An attendance log cannot be moved to another employee.');

        $attendanceLog->update([
            'date' => $validated['date'],
            'log_in_time' => $validated['time_in'],
            'log_out_time' => $validated['time_out'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
            ...$this->approvalAttributes($request, $validated),
        ]);

        // Only on a real transition — re-saving an already-approved log with no
        // status change shouldn't re-notify the employee.
        if (
            $validated['status'] !== $previousStatus
            && in_array($validated['status'], ['approved', 'rejected'], true)
        ) {
            SendTimeLogApprovedNotification::dispatch($attendanceLog, $validated['status']);
        }

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

    /**
     * A rejection needs a reason; an approval carries who signed it off and when.
     * Fields belonging to the other status are simply ignored — approvalAttributes()
     * blanks them out.
     *
     * @return array<string, list<mixed>>
     */
    private function approvalRules(Request $request): array
    {
        return [
            'approved_by' => ['nullable', 'integer', Rule::in($this->approvers($request->user())->modelKeys())],
            'approved_at' => ['nullable', 'date'],
            'reject_reason' => [
                Rule::requiredIf(fn() => $request->input('status') === 'rejected'),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * Keeps the three approval columns consistent with the status: only an
     * approved log has an approver and a timestamp, only a rejected one a reason,
     * and a log moved back to pending has neither.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function approvalAttributes(Request $request, array $validated): array
    {
        return match ($validated['status']) {
            'approved' => [
                // Blank means "me, now" — the common case when an admin approves inline.
                'approved_by' => $validated['approved_by'] ?? $request->user()->id,
                'approved_at' => $validated['approved_at'] ?? now(),
                'reject_reason' => null,
            ],
            'rejected' => [
                'approved_by' => null,
                'approved_at' => null,
                'reject_reason' => $validated['reject_reason'],
            ],
            default => ['approved_by' => null, 'approved_at' => null, 'reject_reason' => null],
        };
    }

    private function month(string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('!Y-m', $value) : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    private function logPayload(AttendanceLog $log): array
    {
        return [
            'id' => $log->id,
            'employee_id' => $log->employee_id,
            'employee' => $log->relationLoaded('employee') ? [
                'employee_no' => $log->employee->employee_no,
                'full_name' => $log->employee->full_name,
                'company' => $log->employee->company?->name,
            ] : null,
            'date' => $log->date->format('Y-m-d'),
            'time_in' => $log->log_in_time ? substr($log->log_in_time, 0, 5) : null,
            'time_out' => $log->log_out_time ? substr($log->log_out_time, 0, 5) : null,
            'notes' => $log->notes,
            'status' => strtolower($log->status),
            'duration' => $log->duration,
            'duration_minutes' => $log->duration_minutes,
            'approved_by' => $log->approved_by,
            'approved_by_name' => $log->approver?->name,
            // Two shapes: one the datetime-local input accepts, one for the table.
            'approved_at' => $log->approved_at?->format('Y-m-d\TH:i'),
            'approved_at_label' => $log->approved_at?->format('M j, Y g:i A'),
            'reject_reason' => $log->reject_reason,
        ];
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

    private function employeeInScope(Request $request, int $id): Employee
    {
        return Employee::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function assertLogInScope(Request $request, AttendanceLog $log): void
    {
        abort_unless(AttendanceLog::query()->visibleTo($request->user())->whereKey($log->id)->exists(), 403);
    }
}
