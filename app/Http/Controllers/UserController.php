<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\RealtimeUsersKpi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * 'admin' is deliberately absent: the administrator is created once through
     * the /register bootstrap and is never assignable from the UI. The role still
     * exists in Laratrust with its full permission set.
     *
     * @var list<string>
     */
    private const ASSIGNABLE_ROLES = ['company_admin', 'employee'];

    public function index(Request $request): Response
    {
        $viewer = $request->user();

        return Inertia::render('Users/Index', [
            'users' => User::query()
                ->visibleTo($viewer)                  // company_admin -> own company only
                ->with(['company:id,name', 'roles:id,name,display_name'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'is_disabled' => $user->is_disabled,
                    'company' => $user->company?->only(['id', 'name']),
                    'roles' => $user->roles->pluck('display_name')->values(),
                    // Machine name for the edit form; `roles` above is for display.
                    'role' => $user->roles->pluck('name')->first(),
                    // The edit dialog is hidden for the administrator — syncRoles()
                    // there would strip the only admin role and lock everyone out.
                    'is_admin' => $user->hasRole('admin'),
                ]),
            'companies' => Company::visibleTo($viewer)->orderBy('name')->get(['id', 'name']),
            'assignableRoles' => self::ASSIGNABLE_ROLES,
            'can' => [
                'create' => $viewer->isAbleTo('users.create'),
                'edit' => $viewer->isAbleTo('users.edit'),
                'assignAnyCompany' => $viewer->hasRole('admin'),
            ],
        ]);
    }

    public function store(Request $request, RealtimeUsersKpi $realtime): RedirectResponse
    {
        $viewer = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'role' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        // A company_admin can only ever create users inside its own company.
        $companyId = $viewer->hasRole('admin') ? $validated['company_id'] : $viewer->company_id;

        $user = User::create([
            ...collect($validated)->except('role', 'company_id')->all(),
            'company_id' => $companyId,
            'is_disabled' => false,
        ]);

        $user->syncRoles([$validated['role']]);

        $realtime->publish();

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->hasRole('admin') || $viewer->company_id === $user->company_id,
            403,
        );

        // An admin's own role can't be reassigned to a lesser one by accident.
        abort_if($user->hasRole('admin'), 403, 'The administrator account cannot be edited here.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'is_disabled' => ['boolean'],
            'role' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user->update(collect($validated)->except('role')->all());
        $user->syncRoles([$validated['role']]);

        return back()->with('success', 'User updated.');
    }
}
