<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MyAccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user()->load(['company', 'employee']);

        return Inertia::render('MyAccount', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'company' => $user->company?->only(['id', 'name']),
                'roles' => $user->roles->pluck('display_name')->values(),
            ],
            // Employees see their own record here instead of the company-wide list.
            'employee' => $user->employee?->only([
                'employee_no', 'first_name', 'middle_name', 'last_name', 'full_name',
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
        ]);

        $user->update($validated);

        return back()->with('success', 'Your account has been updated.');
    }
}
