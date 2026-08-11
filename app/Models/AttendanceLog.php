<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'date', 'log_in_time', 'log_out_time', 'notes', 'status'])]
class AttendanceLog extends Model
{
    protected $appends = ['duration', 'duration_minutes'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->when(
            ! $viewer->hasRole('admin'),
            fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $employees) => $employees->where('company_id', $viewer->company_id),
            ),
        );
    }

    protected function durationMinutes(): Attribute
    {
        return Attribute::get(function (): int {
            if (! $this->log_in_time || ! $this->log_out_time) {
                return 0;
            }

            $start = strtotime($this->log_in_time);
            $end = strtotime($this->log_out_time);

            return max(0, (int) floor(($end - $start) / 60));
        });
    }

    protected function duration(): Attribute
    {
        return Attribute::get(function (): string {
            $minutes = $this->duration_minutes;

            return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
        });
    }
}
