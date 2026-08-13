<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Observers\DashboardCacheObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[Fillable(['name', 'is_active', 'rate_per_hr'])]
#[ObservedBy(DashboardCacheObserver::class)]

class Company extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_per_hr' => 'decimal:2',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Admin sees every company; anyone else sees only their own. */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->when(
            ! $viewer->hasRole('admin'),
            fn(Builder $q) => $q->whereKey($viewer->company_id),
        );
    }
}
