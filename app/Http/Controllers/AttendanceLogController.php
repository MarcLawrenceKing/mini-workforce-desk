<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceLogController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $isManager = $viewer->hasRole(['admin', 'company_admin']);
        $month = $this->month($request->string('month')->toString());

        if ($isManager) {
            $logs = AttendanceLog::query()
                ->visibleTo($viewer)
                ->whereBetween('date', [$month->startOfMonth(), $month->endOfMonth()])
                ->with(['employee:id,company_id,employee_no,first_name,middle_name,last_name', 'employee.company:id,name'])
                ->latest('date')
                ->latest('log_in_time')
                ->get()
                ->map(fn (AttendanceLog $log) => $this->logPayload($log));

            return Inertia::render('AttendanceLogs/AdminIndex', [
                'logs' => $logs,
                'month' => $month->format('Y-m'),
                'employees' => Employee::query()
                    ->visibleTo($viewer)
                    ->orderBy('last_name')
                    ->get()
                    ->map(fn (Employee $employee) => [
                        'id' => $employee->id,
                        'label' => "{$employee->employee_no} — {$employee->full_name}",
                    ]),
                'statuses' => self::STATUSES,
            ]);
        }

        $employee = $viewer->employee;
        $logs = $employee
            ? $employee->attendanceLogs()
                ->whereBetween('date', [$month->startOfMonth(), $month->endOfMonth()])
                ->orderByDesc('date')
                ->get()
            : collect();

        $workedMinutes = $logs->sum(fn (AttendanceLog $log) => $log->duration_minutes);
        $todayLog = $employee?->attendanceLogs()->whereDate('date', today())->first();

        return Inertia::render('AttendanceLogs/EmployeeIndex', [
            'logs' => $logs->map(fn (AttendanceLog $log) => $this->logPayload($log)),
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
                'required', 'date',
                Rule::unique('attendance_logs', 'date')->where('employee_id', $employee->id),
            ],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => $validated['date'],
            'log_in_time' => $validated['time_in'],
            'log_out_time' => $validated['time_out'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Attendance log created.');
    }

    public function update(Request $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $this->assertManager($request);
        $this->assertLogInScope($request, $attendanceLog);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => [
                'required', 'date',
                Rule::unique('attendance_logs', 'date')
                    ->where('employee_id', $attendanceLog->employee_id)
                    ->ignore($attendanceLog->id),
            ],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        abort_unless((int) $validated['employee_id'] === $attendanceLog->employee_id, 422, 'An attendance log cannot be moved to another employee.');

        $attendanceLog->update([
            'date' => $validated['date'],
            'log_in_time' => $validated['time_in'],
            'log_out_time' => $validated['time_out'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Attendance log updated.');
    }

    public function approve(Request $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $this->assertManager($request);
        $this->assertLogInScope($request, $attendanceLog);

        abort_unless($attendanceLog->status === 'pending', 422, 'Only pending logs can be approved.');
        $attendanceLog->update(['status' => 'approved']);

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
        ];
    }

    private function assertManager(Request $request): void
    {
        abort_unless($request->user()->hasRole(['admin', 'company_admin']), 403);
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
