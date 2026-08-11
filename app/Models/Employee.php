<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'company_id', 'employee_no',
    'first_name', 'middle_name', 'last_name',
])]
class Employee extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $appends = ['full_name'];

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn () => trim(implode(' ', array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
            ]))),
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

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->when(
            ! $viewer->hasRole('admin'),
            fn (Builder $q) => $q->where('company_id', $viewer->company_id),
        );
    }
}
