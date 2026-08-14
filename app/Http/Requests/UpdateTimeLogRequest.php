<?php

namespace App\Http\Requests;

use App\Models\AttendanceLog;
use Illuminate\Validation\Rule;

class UpdateTimeLogRequest extends TimeLogRequest
{
    /**
     * A company_admin, and only for a log inside its own company — the same
     * scope check the read queries use, so a guessed id from another company is
     * a 403 rather than a quiet edit.
     */
    public function authorize(): bool
    {
        return $this->isManager()
            && AttendanceLog::query()
                ->visibleTo($this->user())
                ->whereKey($this->timeLog()->getKey())
                ->exists();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $log = $this->timeLog();

        return [
            // An attendance log belongs to the employee it was created for.
            // Moving it to somebody else would rewrite history, so the only
            // accepted value is the one it already has.
            'employee_id' => ['required', 'integer', Rule::in([$log->employee_id])],
            'date' => ['required', 'date', $this->uniqueDateRule($log)],
            ...$this->timeRules(),
            ...$this->approvalRules(),
        ];
    }

    /**
     * The route model, whatever the parameter happens to be called: the web
     * route uses {attendanceLog}, the API route {timeLog}.
     */
    public function timeLog(): AttendanceLog
    {
        return $this->route('attendanceLog') ?? $this->route('timeLog');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['employee_id.in' => 'An attendance log cannot be moved to another employee.'];
    }
}
