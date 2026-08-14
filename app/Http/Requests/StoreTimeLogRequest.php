<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreTimeLogRequest extends TimeLogRequest
{
    public function authorize(): bool
    {
        return $this->isManager();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Scoped `exists`: naming an employee of another company is a 422, not
            // a silent success. This is the same boundary Employee::visibleTo()
            // enforces on reads.
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('company_id', $this->user()->company_id)
                    ->whereNull('deleted_at'),
            ],
            'date' => ['required', 'date', $this->uniqueDateRule()],
            ...$this->timeRules(),
            ...$this->approvalRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'employee_id' => $this->integer('employee_id'),
            ...parent::toAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['employee_id.exists' => 'That employee is not part of your company.'];
    }
}
