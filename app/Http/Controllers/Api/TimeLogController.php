<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\NotifiesTimeLogDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTimeLogRequest;
use App\Http\Requests\UpdateTimeLogRequest;
use App\Http\Resources\TimeLogResource;
use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Task 9 — the JSON face of attendance logs.
 *
 * Deliberately a sibling of AttendanceLogController rather than a replacement:
 * that one renders Inertia pages and returns redirects, which is exactly wrong
 * for an API. The rules, the scoping and the serialised shape are shared
 * (StoreTimeLogRequest / UpdateTimeLogRequest / TimeLogResource), so the two can
 * never disagree about what a valid log is.
 *
 * Who may call this — unchanged from the web UI:
 *   company_admin  read + write, own company only
 *   employee       read own logs only; every write is a 403
 *   admin          no attendance-logs.view permission at all → 403 at the route
 */
class TimeLogController extends Controller
{
    use NotifiesTimeLogDecision;

    /**
     * GET /api/time-logs?month=YYYY-MM&status=pending&employee_id=3
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $month = $this->month($request->string('month')->toString());

        $logs = $this->scope($request)
            ->whereBetween('date', [$month->startOfMonth(), $month->endOfMonth()])
            ->when(
                $request->filled('status'),
                fn(Builder $q) => $q->where('status', $request->string('status')->lower()->toString()),
            )
            ->when(
                $request->filled('employee_id'),
                fn(Builder $q) => $q->where('employee_id', $request->integer('employee_id')),
            )
            ->with(['employee:id,company_id,employee_no,first_name,middle_name,last_name', 'employee.company:id,name', 'approver:id,name'])
            ->latest('date')
            ->latest('log_in_time')
            ->get();

        return TimeLogResource::collection($logs)->additional([
            'meta' => [
                'month' => $month->format('Y-m'),
                'total' => $logs->count(),
                'worked_minutes' => $logs->sum(fn(AttendanceLog $log) => $log->duration_minutes),
            ],
        ]);
    }

    /**
     * GET /api/time-logs/{timeLog}
     */
    public function show(Request $request, AttendanceLog $timeLog): TimeLogResource
    {
        $this->assertReadable($request, $timeLog);

        return TimeLogResource::make(
            $timeLog->load(['employee:id,company_id,employee_no,first_name,middle_name,last_name', 'employee.company:id,name', 'approver:id,name']),
        );
    }

    /**
     * POST /api/time-logs — 201 with the row that was created.
     *
     * Authorisation and validation happen in StoreTimeLogRequest before this
     * method runs, so a 403 or 422 never reaches here.
     */
    public function store(StoreTimeLogRequest $request): JsonResponse
    {
        $log = AttendanceLog::create($request->toAttributes());
        $this->notifyOnDecision($log, 'pending');

        return TimeLogResource::make($log->load('employee', 'approver'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT/PATCH /api/time-logs/{timeLog}
     */
    public function update(UpdateTimeLogRequest $request, AttendanceLog $timeLog): TimeLogResource
    {
        $previousStatus = $timeLog->status;

        $timeLog->update($request->toAttributes());
        $this->notifyOnDecision($timeLog, $previousStatus);

        return TimeLogResource::make($timeLog->load('employee', 'approver'));
    }

    /**
     * PUT /api/time-logs/{timeLog}/approve — the shortcut the Vue table uses.
     * The long way round is a full PUT with status=approved.
     */
    public function approve(Request $request, AttendanceLog $timeLog): TimeLogResource
    {
        $this->assertManager($request);
        $this->assertInScope($request, $timeLog);

        // A ValidationException rather than abort(422): it gives the caller the
        // same {message, errors} envelope every other 422 uses, so a client
        // needs exactly one error handler.
        if ($timeLog->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only a pending log can be approved. This one is already ' . $timeLog->status . '.',
            ]);
        }

        $timeLog->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'reject_reason' => null,
        ]);

        // Queued, not inline — the caller gets its response immediately.
        $this->notifyOnDecision($timeLog, 'pending');

        return TimeLogResource::make($timeLog->load('employee', 'approver'));
    }

    /**
     * DELETE /api/time-logs/{timeLog} — 204, hard delete.
     */
    public function destroy(Request $request, AttendanceLog $timeLog): Response
    {
        $this->assertManager($request);
        $this->assertInScope($request, $timeLog);

        $timeLog->delete();

        return response()->noContent();
    }

    /**
     * The rows this caller is allowed to see at all: a company_admin sees its
     * whole company, an employee sees only the logs attached to its own
     * employee record.
     */
    private function scope(Request $request): Builder
    {
        $user = $request->user();
        $query = AttendanceLog::query()->visibleTo($user);

        if ($user->hasRole('company_admin')) {
            return $query;
        }

        // An employee with no linked employee record can see nothing — never
        // everything.
        return $query->where('employee_id', $user->employee?->id ?? 0);
    }

    private function assertReadable(Request $request, AttendanceLog $log): void
    {
        abort_unless($this->scope($request)->whereKey($log->getKey())->exists(), 403);
    }

    private function assertManager(Request $request): void
    {
        abort_unless($request->user()->hasRole('company_admin'), 403);
    }

    private function assertInScope(Request $request, AttendanceLog $log): void
    {
        abort_unless(
            AttendanceLog::query()->visibleTo($request->user())->whereKey($log->getKey())->exists(),
            403,
        );
    }

    /** An unparseable ?month= falls back to the current month rather than erroring. */
    private function month(string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('!Y-m', $value) : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }
}
