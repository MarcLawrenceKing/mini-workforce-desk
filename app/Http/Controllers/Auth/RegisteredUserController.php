<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        // No company select: the platform admin isn't scoped to a company.
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $roleClass = config('laratrust.models.role');

        abort_unless(
            $roleClass::where('name', 'admin')->exists(),
            503,
            'Roles have not been seeded yet. Run: php artisan db:seed --class=RolesAndPermissionsSeeder',
        );

        $user = DB::transaction(function () use ($validated) {
            // EnsureNoAdminExists ran before this request took a lock, so two
            // simultaneous submits could otherwise both slip past it.
            abort_if(User::adminExists(), 403, 'Registration is closed.');

            $user = User::create([
                ...$validated,
                'company_id' => null,
                'is_disabled' => false,
            ]);

            $user->addRole('admin');

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()
            ->route('my-account')
            ->with('success', 'Administrator account created. Welcome!');
    }
}
