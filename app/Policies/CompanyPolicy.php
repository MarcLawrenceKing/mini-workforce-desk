<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAbleTo('companies.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->isAbleTo('companies.view') && $this->inScope($user, $company);
    }

    public function create(User $user): bool
    {
        return $user->isAbleTo('companies.create');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isAbleTo('companies.edit') && $this->inScope($user, $company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * The whole "company_admin cannot manage other companies" rule lives here:
     * admin is unscoped, everyone else is pinned to their own company_id.
     */
    private function inScope(User $user, Company $company): bool
    {
        return $user->hasRole('admin') || $user->company_id === $company->id;
    }
}
