<?php

namespace App\Http\Requests;

use App\Models\AttendanceLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything the create and update requests share. Both the Inertia controller
 * and the Task 9 JSON API extend from here, so a rule only ever lives in one
 * place and the web UI and the API can never validate differently.
 */
abstract class TimeLogRequest extends FormRequest
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    /**
     * The fields common to both, minus `employee_id` and the date-uniqueness
     * rule, which differ between create and update.
     *
     * @return array<string, list<mixed>>
     */
    protected function timeRules(): array
    {
        return [
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }

    /**
     * "One log per employee per day", as a closure rather than Rule::unique.
     *
     * Rule::unique compares the raw column, and Eloquent writes a date-cast
     * attribute as `2026-08-13 00:00:00`. MySQL's DATE column drops the time on
     * the way in so the comparison happens to work there — SQLite keeps it, so
     * the same rule silently never matches. whereDate() normalises on both.
     */
    protected function uniqueDateRule(?AttendanceLog $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $employeeId = $ignore?->employee_id ?? $this->integer('employee_id');

            $clash = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->whereDate('date', $value)
                ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($clash) {
                $fail('That employee already has an attendance log for this date.');
            }
        };
    }

    /**
     * A rejection needs a reason; an approval carries who signed it off and when.
     * Fields belonging to the other status are simply ignored — approvalAttributes()
     * blanks them out.
     *
     * @return array<string, list<mixed>>
     */
    protected function approvalRules(): array
    {
        return [
            'approved_by' => ['nullable', 'integer', Rule::in($this->approvers()->modelKeys())],
            'approved_at' => ['nullable', 'date'],
            'reject_reason' => [
                Rule::requiredIf(fn () => $this->input('status') === 'rejected'),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * The company_admins a log may be signed off by — the approver dropdown, and
     * the whitelist the submitted approved_by is checked against.
     *
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        return User::query()
            ->visibleTo($this->user())
            ->where('is_disabled', false)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'company_admin'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The validated input translated into database columns. The request owns this
     * mapping because the request owns the field names — `time_in` is a form
     * concern, `log_in_time` is a column.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $validated = $this->validated();

        return [
            'date' => $validated['date'],
            'log_in_time' => $validated['time_in'],
            'log_out_time' => $validated['time_out'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
            ...$this->approvalAttributes($validated),
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
    private function approvalAttributes(array $validated): array
    {
        return match ($validated['status']) {
            'approved' => [
                // Blank means "me, now" — the common case when an admin approves inline.
                'approved_by' => $validated['approved_by'] ?? $this->user()->id,
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

    /**
     * Only a company_admin writes attendance logs. An `employee` may read their
     * own but never create or edit; `admin` never holds attendance-logs.view at
     * all and is stopped by the route middleware before reaching here.
     */
    protected function isManager(): bool
    {
        return (bool) $this->user()?->hasRole('company_admin');
    }
}
