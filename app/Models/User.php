<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;

#[Fillable(['company_id', 'name', 'email', 'username', 'password', 'is_disabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements LaratrustUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRolesAndPermissions, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_disabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Whether the platform has been bootstrapped. Registration is open only
     * while this is false — see EnsureNoAdminExists.
     */
    public static function adminExists(): bool
    {
        return static::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'admin'))
            ->exists();
    }

    /**
     * Admins are the only role that reaches across company boundaries;
     * everyone else is pinned to their own company_id.
     */
    public function scopeVisibleTo(Builder $query, self $viewer): Builder
    {
        return $query->when(
            ! $viewer->hasRole('admin'),
            fn (Builder $q) => $q->where('company_id', $viewer->company_id),
        );
    }
}
