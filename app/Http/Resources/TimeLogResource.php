<?php

namespace App\Http\Resources;

use App\Models\AttendanceLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape an attendance log is serialised into — used by the Inertia pages
 * (Task 5-8) and by the JSON API (Task 9) alike, so the two can never drift.
 *
 * @mixin AttendanceLog
 */
class TimeLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'employee_no' => $this->employee->employee_no,
                'full_name' => $this->employee->full_name,
                'company' => $this->employee->company?->name,
            ], null),
            'date' => $this->date->format('Y-m-d'),
            // H:i, not H:i:s — this is what the <input type="time"> fields expect.
            'time_in' => $this->log_in_time ? substr($this->log_in_time, 0, 5) : null,
            'time_out' => $this->log_out_time ? substr($this->log_out_time, 0, 5) : null,
            'notes' => $this->notes,
            'status' => strtolower($this->status),
            'duration' => $this->duration,
            'duration_minutes' => $this->duration_minutes,
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->approver?->name,
            // Two shapes: one the datetime-local input accepts, one for the table.
            'approved_at' => $this->approved_at?->format('Y-m-d\TH:i'),
            'approved_at_label' => $this->approved_at?->format('M j, Y g:i A'),
            'reject_reason' => $this->reject_reason,
        ];
    }
}
