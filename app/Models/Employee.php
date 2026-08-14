<?php

namespace App\Models;

use App\Observers\DashboardCacheObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'company_id',
    'employee_no',
    'first_name',
    'middle_name',
    'last_name',
    'photo_url',
])]
#[ObservedBy(DashboardCacheObserver::class)]

class Employee extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $appends = ['full_name'];

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn() => trim(implode(' ', array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
            ]))),
        );
    }

    /** Convert the stored disk path into the URL served through public/storage. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn (?string $path) => $path ? Storage::disk('public')->url($path) : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->when(
            ! $viewer->hasRole('admin'),
            fn(Builder $q) => $q->where('company_id', $viewer->company_id),
        );
    }
}
