# Task 3 & 4 — Auth + Laratrust RBAC (code + explanation)

> Nothing here is applied yet. Review, then say the word and I'll implement it.

Stack confirmed from your repo: Laravel **13.24**, PHP **8.5**, Inertia **3**, Vue **3.5**,
PrimeVue **4.5**, Tailwind **4**, MySQL (`mini_workforce_desk`).

---

## 0. Decisions (yours, applied)

| # | Decision | Consequence in the code |
|---|---|---|
| 1 | `/task2` stays public, untouched | It's back in `routes/web.php` outside every middleware group. Still renders `Dashboard.vue` with the hardcoded staff array. |
| 2 | `/my-account` is the authenticated landing page | There is **no** `/dashboard` route and no `DashboardController`. `redirectUsersTo`, `intended()`, post-register, and the 403 redirect all point at `/my-account`. Task 3's "redirect guests away from dashboard" is satisfied by `/my-account` (and every other private route) bouncing guests to `/login`. |
| 3 | Admin nav includes `/employees` | `NAV_BY_ROLE.admin = [myAccount, users, companies, employees]`. Admin already holds `employees.*`, so nothing changes server-side. |
| 4 | `/register` is a **one-time admin bootstrap** | Public, but closed the instant an admin exists. Creates an `admin` (not `employee`), with `company_id = null`. Enforced by an `EnsureNoAdminExists` middleware **plus** a re-check inside a DB transaction. Company select removed from the form. |

Two more calls I made, carried over from the last pass:

| # | Decision | Why |
|---|---|---|
| 5 | Laratrust pinned to `^8.5.5` | I checked packagist: `8.5.5` is the **first** release whose constraint is `laravel/framework: ^10\|^11\|^12\|^13`. `8.5.3` and below cap at `^12.0` and composer will refuse to install on your Laravel 13. |
| 6 | **`employee` role does NOT get `employees.view`** | Your spec asked for both `employee → employees.view` *and* "employee cannot access `/employees`". `/employees` is gated on `permission:employees.view`, so the employee must not hold it. The employee still sees their own record — `/my-account` is auth-only with no permission gate, and the controller loads `$user->employee` directly. |
| 7 | Added `attendance-logs.view` and `requests.view` | Your employee/company_admin navs point at those routes; they need something to gate on. |
| 8 | 403 → **redirect** for browser visits, **hard 403** for JSON | Satisfies "gets 403/redirect" both ways from one handler in `bootstrap/app.php`. |

---

## 1. Install & scaffold (run these, in order)

```bash
composer require santigarcor/laratrust:^8.5.5

php artisan vendor:publish --tag="laratrust"   # -> config/laratrust.php + config/laratrust_seeder.php
php artisan laratrust:setup                    # -> migration + adds the trait to App\Models\User
composer dump-autoload
php artisan migrate
```

`laratrust:setup` creates the `roles`, `permissions`, `role_user`, `permission_role`,
`permission_user` tables and injects `Laratrust\Traits\HasRolesAndPermissions` into your
`User` model. **After it runs, open `config/laratrust.php` and confirm the `models` key** —
depending on the release it points at either `Laratrust\Models\Role` or `App\Models\Role`.
The seeder in §4.1 reads the class out of config, so it works either way. If it points at
`App\Models\Role` and those files don't exist, create them:

```php
// app/Models/Role.php
<?php
namespace App\Models;
use Laratrust\Models\Role as LaratrustRole;
class Role extends LaratrustRole {}
```
```php
// app/Models/Permission.php
<?php
namespace App\Models;
use Laratrust\Models\Permission as LaratrustPermission;
class Permission extends LaratrustPermission {}
```

Also confirm in `config/laratrust.php`:

```php
'user_models' => [
    'users' => \App\Models\User::class,
],

'middleware' => [
    'register' => true,     // registers the role: / permission: / ability: aliases
    'handling' => 'abort',  // leave as abort(403); we convert 403 -> redirect ourselves
    'handlers' => [
        'abort' => ['code' => 403],
    ],
],
```

No npm installs needed — every PrimeVue component below is already in `primevue@4.5`.

---

## 2. Models

### 2.1 `app/Models/User.php` (replace)

```php
<?php

namespace App\Models;

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
```

`implements LaratrustUser` + `use HasRolesAndPermissions` is what gives you
`$user->hasRole()`, `$user->isAbleTo()`, `$user->addRole()`, `$user->syncRoles()`,
`$user->roles`, `$user->allPermissions()`.

### 2.2 `app/Models/Company.php` (new)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active'])]
class Company extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
            fn (Builder $q) => $q->whereKey($viewer->company_id),
        );
    }
}
```

### 2.3 `app/Models/Employee.php` (new)

```php
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

    protected $appends = ['full_name'];

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn () => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"),
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
```

> Heads-up from your migration: `employees.middle_name` is **NOT NULL**. The seeder and any
> create form must always send it (empty string is fine, `null` throws).

---

## 3. Task 3 — Authentication

### 3.1 `app/Http/Controllers/Controller.php` (replace)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

Laravel 11+ ships a bare base controller — without this trait `$this->authorize()` doesn't exist.

### 3.2 `app/Http/Requests/Auth/LoginRequest.php` (new)

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Task 4: a disabled account must never end up holding a session.
        if (Auth::user()->is_disabled) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'Your account has been disabled. Please contact your administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
```

### 3.3 `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (new)

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            // The "Register" link only makes sense before the first admin exists.
            'canRegister' => ! User::adminExists(),
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('my-account'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
```

### 3.4 `app/Http/Middleware/EnsureNoAdminExists.php` (new) — decision 4

```php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration is a one-time bootstrap step: it exists only to create the very
 * first administrator on a fresh install. Once an admin exists the route closes
 * for good and every further account is created from /users by a signed-in user.
 */
class EnsureNoAdminExists
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::adminExists()) {
            if ($request->expectsJson()) {
                abort(403, 'Registration is closed.');
            }

            return redirect()->route('login')->with(
                'error',
                'Registration is closed — an administrator account already exists.',
            );
        }

        return $next($request);
    }
}
```

### 3.5 `app/Http/Controllers/Auth/RegisteredUserController.php` (new) — decision 4

```php
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
```

### 3.6 `app/Http/Middleware/HandleInertiaRequests.php` — the `share()` method

```php
    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // One query per relation instead of one per hasRole()/isAbleTo() call downstream.
        $user?->loadMissing(['company', 'roles.permissions', 'permissions']);

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'is_disabled' => $user->is_disabled,
                    'company' => $user->company?->only(['id', 'name']),
                    'roles' => $user->roles->pluck('name')->values(),
                    'permissions' => $user->allPermissions()->pluck('name')->unique()->values(),
                ] : null,
            ],

            // Lazily evaluated so the session isn't touched on every render.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
```

That's the "share auth user (and roles) to frontend via Inertia shared data" bullet. `errors`
is already shared by `parent::share()`, so `useForm().errors` works with no extra wiring.

### 3.7 `bootstrap/app.php` (replace)

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            'setup' => \App\Http\Middleware\EnsureNoAdminExists::class,
        ]);

        // Task 3: guests bounced off private routes, auth users bounced off /login.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('my-account'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Task 4 proof: an unauthorized role gets a hard 403 for JSON/API clients,
        // and a redirect to /my-account with a flash message for browser page visits.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 403) {
                return $response;
            }

            if (! $request->user() || $request->expectsJson() || $request->is('api/*')) {
                return $response;
            }

            return redirect()
                ->route('my-account')
                ->with('error', 'You do not have permission to open that page.');
        });
    })->create();
```

---

## 4. Task 4 — Laratrust roles, permissions, seeders

### 4.1 `database/seeders/RolesAndPermissionsSeeder.php` (new)

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Module.action permission names, kept flat so the Vue side can do
     * a plain `permissions.includes('users.view')` check.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'users.view' => 'View users',
        'users.create' => 'Create users',
        'users.edit' => 'Edit users',

        'companies.view' => 'View companies',
        'companies.create' => 'Create companies',
        'companies.edit' => 'Edit companies',

        'employees.view' => 'View employees',
        'employees.create' => 'Create employees',
        'employees.edit' => 'Edit employees',

        'attendance-logs.view' => 'View attendance logs',
        'requests.view' => 'View requests',
    ];

    /**
     * @var array<string, array{display_name: string, permissions: list<string>}>
     */
    private const ROLES = [
        'admin' => [
            'display_name' => 'Administrator',
            'permissions' => [
                'users.view', 'users.create', 'users.edit',
                'companies.view', 'companies.create', 'companies.edit',
                'employees.view', 'employees.create', 'employees.edit',
            ],
        ],
        'company_admin' => [
            'display_name' => 'Company Administrator',
            // No companies.create: a company_admin manages its own company, never new ones.
            'permissions' => [
                'users.view', 'users.create', 'users.edit',
                'companies.view', 'companies.edit',
                'employees.view', 'employees.create', 'employees.edit',
                'requests.view',
            ],
        ],
        'employee' => [
            'display_name' => 'Employee',
            // Deliberately no employees.view: that permission gates the company-wide
            // /employees list. An employee reaches their own record via /my-account.
            'permissions' => [
                'attendance-logs.view',
                'requests.view',
            ],
        ],
    ];

    public function run(): void
    {
        $roleClass = config('laratrust.models.role');
        $permissionClass = config('laratrust.models.permission');

        $permissions = [];

        foreach (self::PERMISSIONS as $name => $displayName) {
            $permissions[$name] = $permissionClass::updateOrCreate(
                ['name' => $name],
                ['display_name' => $displayName],
            );
        }

        foreach (self::ROLES as $name => $definition) {
            $role = $roleClass::updateOrCreate(
                ['name' => $name],
                ['display_name' => $definition['display_name']],
            );

            $role->syncPermissions(
                array_map(fn (string $p) => $permissions[$p], $definition['permissions']),
            );
        }
    }
}
```

### 4.2 `database/seeders/DemoDataSeeder.php` (new) — companies, users, employee records

```php
<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $companyOne = Company::updateOrCreate(['name' => 'Company One'], ['is_active' => true]);
        $companyTwo = Company::updateOrCreate(['name' => 'Company Two'], ['is_active' => true]);

        // Only ever one admin. If you registered one through /register, this is skipped
        // so the seeder can't quietly create a second and defeat the bootstrap rule.
        if (! User::adminExists()) {
            $this->makeUser('admin@test.com', 'admin', 'System Admin', null, 'admin');
            $this->command?->info('Seeded admin@test.com (no admin existed).');
        } else {
            $this->command?->warn('An admin already exists — skipping admin@test.com.');
        }

        // Company One
        $this->makeUser('admin@company1.com', 'company1admin', 'Company One Admin', $companyOne, 'company_admin');

        $employeeUser = $this->makeUser(
            'employee@company1.com', 'company1employee', 'Company One Employee', $companyOne, 'employee'
        );

        Employee::updateOrCreate(
            ['user_id' => $employeeUser->id],
            [
                'company_id' => $companyOne->id,
                'employee_no' => 'C1-0001',
                'first_name' => 'Ada',
                'middle_name' => 'B',   // NOT NULL in the migration
                'last_name' => 'Lovelace',
            ],
        );

        // Proves EnsureAccountIsActive without having to edit a row by hand.
        $disabled = $this->makeUser(
            'disabled@company1.com', 'company1disabled', 'Disabled Employee', $companyOne, 'employee'
        );
        $disabled->update(['is_disabled' => true]);

        // A second company so "company_admin cannot manage other companies" is testable.
        $this->makeUser('admin@company2.com', 'company2admin', 'Company Two Admin', $companyTwo, 'company_admin');
    }

    private function makeUser(
        string $email,
        string $username,
        string $name,
        ?Company $company,
        string $role,
    ): User {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => $username,
                'company_id' => $company?->id,
                'password' => 'password',   // hashed by the model cast
                'is_disabled' => false,
            ],
        );

        $user->syncRoles([$role]);

        return $user;
    }
}
```

**Seeded logins** — all `password`:

| Email | Role | Company |
|---|---|---|
| `admin@test.com` | admin *(only if none exists yet)* | — |
| `admin@company1.com` | company_admin | Company One |
| `employee@company1.com` | employee | Company One |
| `disabled@company1.com` | employee (disabled) | Company One |
| `admin@company2.com` | company_admin | Company Two |

### 4.3 `database/seeders/DatabaseSeeder.php` (replace)

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
```

The old `User::factory()->create(['email' => 'test@example.com'])` has to go — `users.username`
is `NOT NULL UNIQUE` and the factory doesn't fill it.

> **To demo the `/register` bootstrap flow**, don't run the full seeder — see §7.

### 4.4 `database/factories/UserFactory.php` — patch `definition()`

```php
    public function definition(): array
    {
        return [
            'company_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_disabled' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['is_disabled' => true]);
    }
```

### 4.5 `app/Http/Middleware/EnsureAccountIsActive.php` (new)

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kills the session of a user that was disabled *after* they logged in.
 * LoginRequest blocks them at the door; this blocks them on every request after.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_disabled) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'Your account has been disabled.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been disabled. Please contact your administrator.',
            ]);
        }

        return $next($request);
    }
}
```

### 4.6 `app/Policies/CompanyPolicy.php` (new)

```php
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
```

Laravel 12/13 auto-discovers `App\Policies\CompanyPolicy` for `App\Models\Company` — no
registration needed.

### 4.7 `routes/web.php` (replace)

```php
<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MyAccountController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => Inertia::render('Welcome', [
    'title' => 'Mini Workforce Desk',
    'description' => 'A small Laravel + Inertia + Vue workspace for tracking staff, roles, and availability.',
]))->name('home');

// Task 2 demo page — intentionally left public and unauthenticated.
Route::get('/task2', fn () => Inertia::render('Dashboard', [
    'staff' => [
        ['id' => 1, 'name' => 'Ada Lovelace', 'role' => 'Engineer', 'status' => 'Active'],
        ['id' => 2, 'name' => 'Grace Hopper', 'role' => 'Manager',  'status' => 'On leave'],
    ],
]))->name('task2');

/*
|--------------------------------------------------------------------------
| Guests only — an authenticated visitor is redirected to /my-account
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    // One-time bootstrap: 'setup' closes these the moment an admin exists.
    Route::middleware('setup')->group(function () {
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store']);
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated + not disabled — a guest is redirected to /login
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Landing page for every role. No permission gate on purpose: everyone
    // may see and edit their own account.
    Route::get('/my-account', [MyAccountController::class, 'edit'])->name('my-account');
    Route::put('/my-account', [MyAccountController::class, 'update'])->name('my-account.update');

    // --- Users -----------------------------------------------------------
    Route::prefix('users')->name('users.')->middleware('permission:users.view')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');

        Route::middleware('permission:users.create')->group(function () {
            Route::post('/', [UserController::class, 'store'])->name('store');
        });

        Route::middleware('permission:users.edit')->group(function () {
            Route::put('/{user}', [UserController::class, 'update'])->name('update');
        });
    });

    // --- Companies -------------------------------------------------------
    Route::prefix('companies')->name('companies.')->middleware('permission:companies.view')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->name('index');

        // admin only — company_admin has no companies.create permission
        Route::middleware('permission:companies.create')->group(function () {
            Route::post('/', [CompanyController::class, 'store'])->name('store');
        });

        Route::middleware('permission:companies.edit')->group(function () {
            Route::put('/{company}', [CompanyController::class, 'update'])->name('update');
        });

        // Role middleware (rather than permission) for the destructive action.
        Route::middleware('role:admin')->group(function () {
            Route::delete('/{company}', [CompanyController::class, 'destroy'])->name('destroy');
        });
    });

    // --- Employees -------------------------------------------------------
    Route::prefix('employees')->name('employees.')->middleware('permission:employees.view')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');

        Route::middleware('permission:employees.create')->group(function () {
            Route::post('/', [EmployeeController::class, 'store'])->name('store');
        });

        Route::middleware('permission:employees.edit')->group(function () {
            Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
        });
    });

    // --- Placeholders ----------------------------------------------------
    Route::get('/attendance-logs', fn () => Inertia::render('UnderConstruction', [
        'title' => 'Attendance Logs',
        'blurb' => 'Daily time-in / time-out records land here in a later task.',
    ]))->middleware('permission:attendance-logs.view')->name('attendance-logs.index');

    Route::get('/requests', fn () => Inertia::render('UnderConstruction', [
        'title' => 'Requests',
        'blurb' => 'Leave and overtime approvals land here in a later task.',
    ]))->middleware('permission:requests.view')->name('requests.index');
});
```

`role:` and `permission:` are Laratrust's own middleware aliases (auto-registered by its
service provider when `laratrust.middleware.register` is `true`). Syntax reminders:
`permission:users.view|users.edit` = OR, `permission:users.view|users.edit,require` = AND,
`role:admin|company_admin` = either role.

### 4.8 Controllers

`app/Http/Controllers/MyAccountController.php`

```php
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
```

`app/Http/Controllers/CompanyController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);

        return Inertia::render('Companies/Index', [
            'companies' => Company::query()
                ->visibleTo($request->user())          // company_admin only ever sees its own
                ->withCount(['users', 'employees'])
                ->orderBy('name')
                ->get(),
            'can' => [
                'create' => $request->user()->isAbleTo('companies.create'),
                'edit' => $request->user()->isAbleTo('companies.edit'),
                'delete' => $request->user()->hasRole('admin'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Company::class);

        Company::create($request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Company created.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        // 403 when a company_admin targets a company that isn't theirs.
        $this->authorize('update', $company);

        $company->update($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($company->id)],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Company updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        return back()->with('success', 'Company deleted.');
    }
}
```

`app/Http/Controllers/UserController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        return Inertia::render('Users/Index', [
            'users' => User::query()
                ->visibleTo($viewer)                   // company_admin -> own company only
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
                ]),
            'companies' => Company::visibleTo($viewer)->orderBy('name')->get(['id', 'name']),
            'assignableRoles' => $this->assignableRoles($viewer),
            'can' => [
                'create' => $viewer->isAbleTo('users.create'),
                'edit' => $viewer->isAbleTo('users.edit'),
                'assignAnyCompany' => $viewer->hasRole('admin'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $viewer = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'role' => ['required', 'string', Rule::in($this->assignableRoles($viewer))],
        ]);

        // A company_admin can only ever create users inside its own company.
        $companyId = $viewer->hasRole('admin') ? $validated['company_id'] : $viewer->company_id;

        $user = User::create([
            ...collect($validated)->except('role', 'company_id')->all(),
            'company_id' => $companyId,
            'is_disabled' => false,
        ]);

        $user->syncRoles([$validated['role']]);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->hasRole('admin') || $viewer->company_id === $user->company_id,
            403,
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'is_disabled' => ['boolean'],
            'role' => ['required', 'string', Rule::in($this->assignableRoles($viewer))],
        ]);

        $user->update(collect($validated)->except('role')->all());
        $user->syncRoles([$validated['role']]);

        return back()->with('success', 'User updated.');
    }

    /**
     * 'admin' is deliberately absent everywhere: the platform admin is created
     * once through /register and is never mintable from the UI.
     *
     * @return list<string>
     */
    private function assignableRoles(User $viewer): array
    {
        return $viewer->hasRole('admin')
            ? ['company_admin', 'employee']
            : ['company_admin', 'employee'];
    }
}
```

> Note the consequence of decision 4: since `/register` is the only path to an `admin` role
> and it closes after the first one, **`admin` is not assignable from `/users` either**.
> If you want an existing admin to be able to promote someone, change the first branch of
> `assignableRoles()` to include `'admin'` — say the word and I'll do that instead.

`app/Http/Controllers/EmployeeController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        return Inertia::render('Employees/Index', [
            'employees' => Employee::query()
                ->visibleTo($viewer)
                ->with(['company:id,name', 'user:id,email'])
                ->orderBy('last_name')
                ->get(),
            'can' => [
                'create' => $viewer->isAbleTo('employees.create'),
                'edit' => $viewer->isAbleTo('employees.edit'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $viewer = $request->user();

        $validated = $request->validate([
            'employee_no' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_no')->where('company_id', $viewer->company_id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],   // NOT NULL in the migration
            'last_name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:employees,user_id'],
        ]);

        Employee::create([
            ...$validated,
            'company_id' => $viewer->company_id,
        ]);

        return back()->with('success', 'Employee created.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->hasRole('admin') || $viewer->company_id === $employee->company_id,
            403,
        );

        $employee->update($request->validate([
            'employee_no' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_no')
                    ->where('company_id', $employee->company_id)
                    ->ignore($employee->id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ]));

        return back()->with('success', 'Employee updated.');
    }
}
```

---

## 5. Frontend (Inertia + Vue + PrimeVue)

### 5.1 `resources/js/app.js` — one change

```js
import AuthLayout from "./Layouts/AuthLayout.vue";
// ...
        // Auth screens get a bare centred layout; everything else gets the app chrome.
        page.default.layout ??= name.startsWith("Auth/") ? AuthLayout : AppLayout;
```

The unused `Button`/`Card`/`DataTable`/`Column`/`Tag` imports at the top of `app.js` are dead
(never `.component()`-registered) — pages import them directly. Safe to delete.

### 5.2 `resources/js/Composables/useAuth.js` (new)

```js
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Thin reader over the `auth` prop shared by HandleInertiaRequests.
 * Nothing here is a security boundary — it only decides what to render.
 * Every one of these checks is enforced again server-side.
 */
export function useAuth() {
    const page = usePage();

    const user = computed(() => page.props.auth?.user ?? null);
    const roles = computed(() => user.value?.roles ?? []);
    const permissions = computed(() => user.value?.permissions ?? []);
    const isLoggedIn = computed(() => user.value !== null);

    /** hasRole("admin") | hasRole("admin", "company_admin") -> OR */
    const hasRole = (...names) => names.flat().some((n) => roles.value.includes(n));

    /** can("users.view") | can("users.view", "users.edit") -> OR */
    const can = (...names) => names.flat().some((n) => permissions.value.includes(n));

    return { user, roles, permissions, isLoggedIn, hasRole, can };
}
```

### 5.3 `resources/js/Components/FlashMessages.vue` (new)

Shared by both layouts, so the "registration is closed" / "no permission" banners show on the
auth screens too.

```vue
<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import Message from "primevue/message";

const page = usePage();

const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div v-if="flash.success || flash.error" class="stack">
        <Message v-if="flash.success" severity="success" :closable="true">
            {{ flash.success }}
        </Message>
        <Message v-if="flash.error" severity="error" :closable="true">
            {{ flash.error }}
        </Message>
    </div>
</template>
```

### 5.4 `resources/js/Components/AppHeader.vue` (replace) — decision 3

```vue
<script setup>
import { computed, onBeforeMount, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { useAuth } from "../Composables/useAuth";
import Button from "primevue/button";
import Menu from "primevue/menu";

const { user, roles, isLoggedIn } = useAuth();

/* ---- theme toggle (unchanged) ---------------------------------------- */
const isDark = ref(false);

function applyTheme(dark) {
    isDark.value = dark;
    document.documentElement.classList.toggle("dark", dark);
    localStorage.setItem("theme", dark ? "dark" : "light");
}

onBeforeMount(() => {
    const stored = localStorage.getItem("theme");

    applyTheme(
        stored
            ? stored === "dark"
            : window.matchMedia("(prefers-color-scheme: dark)").matches,
    );
});

/* ---- role-driven navigation ------------------------------------------ */
const LINKS = {
    myAccount: { label: "My Account", href: "/my-account", icon: "pi pi-user" },
    users: { label: "Users", href: "/users", icon: "pi pi-users" },
    companies: { label: "Companies", href: "/companies", icon: "pi pi-building" },
    employees: { label: "Employees", href: "/employees", icon: "pi pi-id-card" },
    attendanceLogs: { label: "Attendance Logs", href: "/attendance-logs", icon: "pi pi-clock" },
    requests: { label: "Requests", href: "/requests", icon: "pi pi-inbox" },
};

const NAV_BY_ROLE = {
    admin: ["myAccount", "users", "companies", "employees"],
    company_admin: ["myAccount", "users", "companies", "employees", "requests"],
    employee: ["myAccount", "attendanceLogs", "requests"],
};

// A user with more than one role gets the union, first-seen order preserved.
const navItems = computed(() => {
    if (!isLoggedIn.value) return [];

    const keys = roles.value.flatMap((role) => NAV_BY_ROLE[role] ?? []);

    return [...new Set(keys)].map((key) => LINKS[key]);
});

const userMenu = ref(null);
const userMenuItems = [
    { label: "My Account", icon: "pi pi-user", command: () => router.get("/my-account") },
    { separator: true },
    { label: "Log out", icon: "pi pi-sign-out", command: () => router.post("/logout") },
];
</script>

<template>
    <header class="app-header">
        <div class="app-shell flex items-center justify-between gap-4 py-3">
            <Link href="/" class="flex items-center gap-2 font-semibold no-underline">
                <i class="pi pi-users" />
                Mini Workforce Desk
            </Link>

            <nav v-if="isLoggedIn" class="hidden items-center gap-1 md:flex">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="no-underline"
                >
                    <Button
                        :label="item.label"
                        :icon="item.icon"
                        severity="secondary"
                        text
                        size="small"
                    />
                </Link>
            </nav>

            <div class="flex items-center gap-1">
                <Button
                    :icon="isDark ? 'pi pi-sun' : 'pi pi-moon'"
                    :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    severity="secondary"
                    text
                    rounded
                    @click="applyTheme(!isDark)"
                />

                <template v-if="isLoggedIn">
                    <Button
                        :label="user.name"
                        icon="pi pi-user"
                        severity="secondary"
                        text
                        size="small"
                        aria-haspopup="true"
                        aria-controls="user-menu"
                        @click="userMenu.toggle($event)"
                    />
                    <Menu id="user-menu" ref="userMenu" :model="userMenuItems" popup />
                </template>

                <Link v-else href="/login" class="no-underline">
                    <Button label="Log in" icon="pi pi-sign-in" size="small" />
                </Link>
            </div>
        </div>
    </header>
</template>
```

> Alternative to `NAV_BY_ROLE`: drive each item off `can("users.view")` etc. That keeps nav and
> backend gates in lockstep automatically. With decision 3 applied the two approaches now
> produce the *same* admin and company_admin navs — they only differ for the employee
> (`requests`/`attendance-logs` would still show, which is what you want anyway). Say the word
> and I'll switch it to the permission-driven version.

### 5.5 `resources/js/Layouts/AppLayout.vue` (replace)

```vue
<script setup>
import AppHeader from "../Components/AppHeader.vue";
import FlashMessages from "../Components/FlashMessages.vue";
</script>

<template>
    <AppHeader />

    <div class="app-shell pt-4">
        <FlashMessages />
    </div>

    <slot />
</template>
```

### 5.6 `resources/js/Layouts/AuthLayout.vue` (new)

```vue
<script setup>
import AppHeader from "../Components/AppHeader.vue";
import FlashMessages from "../Components/FlashMessages.vue";
</script>

<template>
    <AppHeader />

    <main class="flex min-h-[calc(100vh-4rem)] items-center justify-center p-4">
        <div class="stack w-full max-w-md">
            <FlashMessages />
            <slot />
        </div>
    </main>
</template>
```

### 5.7 `resources/js/Pages/Auth/Login.vue` (new)

```vue
<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Card from "primevue/card";
import InputText from "primevue/inputtext";
import Password from "primevue/password";
import Checkbox from "primevue/checkbox";
import Button from "primevue/button";
import Message from "primevue/message";

defineProps({
    status: { type: String, default: null },
    // False once an admin exists — registration is a one-time bootstrap.
    canRegister: { type: Boolean, default: false },
});

const form = useForm({
    email: "",
    password: "",
    remember: false,
});

function submit() {
    form.post("/login", {
        onFinish: () => form.reset("password"),
    });
}
</script>

<template>
    <Head title="Log in" />

    <Card>
        <template #title>Log in</template>
        <template #subtitle>
            <span class="app-muted">Use your work email to continue.</span>
        </template>

        <template #content>
            <Message v-if="status" severity="success" class="mb-4">{{ status }}</Message>

            <form class="stack" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium">Email</label>
                    <InputText
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        :invalid="!!form.errors.email"
                        fluid
                        autofocus
                    />
                    <small v-if="form.errors.email" class="text-red-500">
                        {{ form.errors.email }}
                    </small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="password" class="text-sm font-medium">Password</label>
                    <Password
                        id="password"
                        v-model="form.password"
                        :feedback="false"
                        toggleMask
                        autocomplete="current-password"
                        :invalid="!!form.errors.password"
                        fluid
                    />
                    <small v-if="form.errors.password" class="text-red-500">
                        {{ form.errors.password }}
                    </small>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox v-model="form.remember" inputId="remember" binary />
                    <label for="remember" class="text-sm">Remember me</label>
                </div>

                <Button
                    type="submit"
                    label="Log in"
                    icon="pi pi-sign-in"
                    :loading="form.processing"
                    fluid
                />
            </form>
        </template>

        <template v-if="canRegister" #footer>
            <p class="app-muted text-center text-sm">
                First time here?
                <Link href="/register" class="font-medium">Set up the administrator account</Link>
            </p>
        </template>
    </Card>
</template>
```

### 5.8 `resources/js/Pages/Auth/Register.vue` (new) — decision 4

```vue
<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Card from "primevue/card";
import InputText from "primevue/inputtext";
import Password from "primevue/password";
import Button from "primevue/button";
import Message from "primevue/message";

const form = useForm({
    name: "",
    username: "",
    email: "",
    password: "",
    password_confirmation: "",
});

function submit() {
    form.post("/register", {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
}
</script>

<template>
    <Head title="Set up administrator" />

    <Card>
        <template #title>Set up the administrator</template>
        <template #subtitle>
            <span class="app-muted">This is a one-time step.</span>
        </template>

        <template #content>
            <Message severity="info" :closable="false" class="mb-4">
                This account gets the <strong>Administrator</strong> role and full access to
                every company. Once it exists, registration closes and all further accounts
                are created from the Users page.
            </Message>

            <form class="stack" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <label for="name" class="text-sm font-medium">Full name</label>
                    <InputText id="name" v-model="form.name" :invalid="!!form.errors.name" fluid autofocus />
                    <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="username" class="text-sm font-medium">Username</label>
                    <InputText id="username" v-model="form.username" :invalid="!!form.errors.username" fluid />
                    <small v-if="form.errors.username" class="text-red-500">{{ form.errors.username }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium">Email</label>
                    <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email" fluid />
                    <small v-if="form.errors.email" class="text-red-500">{{ form.errors.email }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="password" class="text-sm font-medium">Password</label>
                    <Password id="password" v-model="form.password" toggleMask :invalid="!!form.errors.password" fluid />
                    <small v-if="form.errors.password" class="text-red-500">{{ form.errors.password }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="password_confirmation" class="text-sm font-medium">Confirm password</label>
                    <Password
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :feedback="false"
                        toggleMask
                        fluid
                    />
                </div>

                <Button
                    type="submit"
                    label="Create administrator"
                    icon="pi pi-shield"
                    :loading="form.processing"
                    fluid
                />
            </form>
        </template>

        <template #footer>
            <p class="app-muted text-center text-sm">
                Already set up?
                <Link href="/login" class="font-medium">Log in</Link>
            </p>
        </template>
    </Card>
</template>
```

### 5.9 `resources/js/Pages/MyAccount.vue` (new) — decision 2, the landing page

```vue
<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import Card from "primevue/card";
import InputText from "primevue/inputtext";
import Button from "primevue/button";
import Tag from "primevue/tag";

const props = defineProps({
    account: { type: Object, required: true },
    employee: { type: Object, default: null },
});

const form = useForm({
    name: props.account.name,
    email: props.account.email,
    username: props.account.username,
});
</script>

<template>
    <Head title="My Account" />

    <div class="app-shell stack py-8">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">My Account</h1>
                <p class="app-muted text-sm">
                    {{ account.company?.name ?? "All companies" }}
                </p>
            </div>
            <div class="flex gap-2">
                <Tag v-for="role in account.roles" :key="role" :value="role" severity="info" />
            </div>
        </div>

        <Card>
            <template #title>Profile</template>
            <template #content>
                <form class="stack max-w-lg" @submit.prevent="form.put('/my-account')">
                    <div class="flex flex-col gap-1">
                        <label for="name" class="text-sm font-medium">Name</label>
                        <InputText id="name" v-model="form.name" :invalid="!!form.errors.name" fluid />
                        <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="email" class="text-sm font-medium">Email</label>
                        <InputText id="email" v-model="form.email" type="email" :invalid="!!form.errors.email" fluid />
                        <small v-if="form.errors.email" class="text-red-500">{{ form.errors.email }}</small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="username" class="text-sm font-medium">Username</label>
                        <InputText id="username" v-model="form.username" :invalid="!!form.errors.username" fluid />
                        <small v-if="form.errors.username" class="text-red-500">{{ form.errors.username }}</small>
                    </div>

                    <div>
                        <Button type="submit" label="Save changes" icon="pi pi-check" :loading="form.processing" />
                    </div>
                </form>
            </template>
        </Card>

        <!-- Employees reach their own record here; they have no /employees access. -->
        <Card v-if="employee">
            <template #title>Employee record</template>
            <template #content>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="app-muted text-sm">Employee no.</dt>
                        <dd class="font-medium">{{ employee.employee_no }}</dd>
                    </div>
                    <div>
                        <dt class="app-muted text-sm">Name</dt>
                        <dd class="font-medium">{{ employee.full_name }}</dd>
                    </div>
                    <div>
                        <dt class="app-muted text-sm">Company</dt>
                        <dd class="font-medium">{{ account.company?.name ?? "—" }}</dd>
                    </div>
                </dl>
            </template>
        </Card>
    </div>
</template>
```

### 5.10 `resources/js/Pages/UnderConstruction.vue` (new)

```vue
<script setup>
import { Head } from "@inertiajs/vue3";
import Card from "primevue/card";

defineProps({
    title: { type: String, required: true },
    blurb: { type: String, default: "" },
});
</script>

<template>
    <Head :title="title" />

    <div class="app-shell stack py-8">
        <h1 class="text-2xl font-semibold">{{ title }}</h1>

        <Card>
            <template #content>
                <div class="flex flex-col items-center gap-3 py-10 text-center">
                    <i class="pi pi-wrench app-muted" style="font-size: 2rem" />
                    <p class="text-lg font-medium">Under construction</p>
                    <p class="app-muted max-w-md text-sm">{{ blurb }}</p>
                </div>
            </template>
        </Card>
    </div>
</template>
```

### 5.11 `resources/js/Pages/Companies/Index.vue` (new)

Same shape works for `Users/Index.vue` and `Employees/Index.vue` — swap the columns.

```vue
<script setup>
import { Head } from "@inertiajs/vue3";
import { useAuth } from "../../Composables/useAuth";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Button from "primevue/button";
import Tag from "primevue/tag";

defineProps({
    companies: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
</script>

<template>
    <Head title="Companies" />

    <div class="app-shell stack py-8">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Companies</h1>

            <!-- hidden for company_admin: no companies.create permission -->
            <Button v-if="can.create" label="Add company" icon="pi pi-plus" />
        </div>

        <p v-if="!hasRole('admin')" class="app-muted text-sm">
            You are seeing only your own company.
        </p>

        <Card>
            <template #content>
                <DataTable :value="companies" dataKey="id" responsiveLayout="scroll">
                    <Column field="name" header="Name" />
                    <Column field="users_count" header="Users" />
                    <Column field="employees_count" header="Employees" />
                    <Column header="Status">
                        <template #body="{ data }">
                            <Tag
                                :value="data.is_active ? 'Active' : 'Inactive'"
                                :severity="data.is_active ? 'success' : 'secondary'"
                            />
                        </template>
                    </Column>
                    <Column header="" style="width: 8rem">
                        <template #body="{ data }">
                            <div class="flex gap-1">
                                <Button v-if="can.edit" icon="pi pi-pencil" text rounded size="small" />
                                <Button
                                    v-if="can.delete"
                                    icon="pi pi-trash"
                                    severity="danger"
                                    text
                                    rounded
                                    size="small"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
```

`Dashboard.vue` stays exactly as it is — it's still what `/task2` renders (decision 1).

---

## 6. Proof — `tests/Feature/RoleAccessTest.php` (new)

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laratrust caches a user's permissions; caching across in-test role
        // changes makes these assertions flaky.
        config(['laratrust.cache.enabled' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    /* ---- Task 3 -------------------------------------------------------- */

    public function test_guests_are_redirected_from_private_routes(): void
    {
        $this->get('/my-account')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
        $this->get('/companies')->assertRedirect('/login');
    }

    public function test_the_public_routes_stay_public(): void
    {
        $this->get('/')->assertOk();
        $this->get('/task2')->assertOk();   // decision 1: left unauthenticated
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get('/login')
            ->assertRedirect('/my-account');
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = $this->userWithRole('employee');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/my-account');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    /* ---- Decision 4: one-time admin registration ----------------------- */

    public function test_registration_creates_the_first_admin(): void
    {
        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'First Admin',
            'username' => 'firstadmin',
            'email' => 'first@admin.test',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect('/my-account');

        $admin = User::where('email', 'first@admin.test')->firstOrFail();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNull($admin->company_id);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_registration_closes_once_an_admin_exists(): void
    {
        $this->userWithRole('admin');

        $this->get('/register')
            ->assertRedirect('/login')
            ->assertSessionHas('error');

        $this->post('/register', [
            'name' => 'Second Admin',
            'username' => 'secondadmin',
            'email' => 'second@admin.test',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'second@admin.test']);
    }

    public function test_the_login_page_hides_the_register_link_once_an_admin_exists(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('canRegister', true));

        $this->userWithRole('admin');

        $this->get('/login')->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    /* ---- Task 4 -------------------------------------------------------- */

    public function test_employee_is_redirected_from_the_employees_page(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get('/employees')
            ->assertRedirect('/my-account')
            ->assertSessionHas('error');
    }

    public function test_employee_gets_a_hard_403_when_the_client_wants_json(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->getJson('/employees')
            ->assertForbidden();
    }

    public function test_company_admin_can_reach_employees_but_not_create_companies(): void
    {
        $company = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $admin = $this->userWithRole('company_admin', $company);

        $this->actingAs($admin)->get('/employees')->assertOk();

        $this->actingAs($admin)
            ->postJson('/companies', ['name' => 'Sneaky Co'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_update_another_company(): void
    {
        $own = Company::create(['name' => 'Own Co', 'is_active' => true]);
        $other = Company::create(['name' => 'Other Co', 'is_active' => true]);

        $admin = $this->userWithRole('company_admin', $own);

        $this->actingAs($admin)
            ->putJson("/companies/{$other->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Other Co', $other->fresh()->name);

        $this->actingAs($admin)
            ->put("/companies/{$own->id}", ['name' => 'Own Co Renamed'])
            ->assertSessionHas('success');
    }

    public function test_company_admin_only_sees_its_own_company(): void
    {
        $own = Company::create(['name' => 'Own Co', 'is_active' => true]);
        Company::create(['name' => 'Other Co', 'is_active' => true]);

        $this->actingAs($this->userWithRole('company_admin', $own))
            ->get('/companies')
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Index')
                ->has('companies', 1)
                ->where('companies.0.name', 'Own Co'));
    }

    public function test_admin_can_manage_every_company(): void
    {
        Company::create(['name' => 'Own Co', 'is_active' => true]);
        $other = Company::create(['name' => 'Other Co', 'is_active' => true]);

        $this->actingAs($this->userWithRole('admin'))
            ->put("/companies/{$other->id}", ['name' => 'Renamed By Admin'])
            ->assertSessionHas('success');

        $this->assertSame('Renamed By Admin', $other->fresh()->name);
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = $this->userWithRole('employee');
        $user->update(['is_disabled' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_disabled_user_is_kicked_out_mid_session(): void
    {
        $user = $this->userWithRole('employee');

        $this->actingAs($user)->get('/my-account')->assertOk();

        $user->update(['is_disabled' => true]);

        $this->actingAs($user)->get('/my-account')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_shared_inertia_props_expose_roles_and_permissions(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/my-account')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.roles.0', 'admin')
                ->where('auth.user.permissions', fn ($p) => in_array('companies.create', $p, true)));
    }
}
```

`assertInertia` ships with `inertiajs/inertia-laravel` — no extra package. `RefreshDatabase`
runs against your MySQL `mini_workforce_desk`; add a `.env.testing` with a separate schema if
you'd rather it didn't.

---

## 7. Run order

**To exercise the `/register` bootstrap** (decision 4) — seed roles only, then create the
admin through the UI:

```bash
php artisan migrate:fresh
php artisan db:seed --class=RolesAndPermissionsSeeder
npm run dev
php artisan serve
# -> visit /register, create the admin, you land on /my-account
php artisan db:seed --class=DemoDataSeeder     # adds the company_admin + employee demo users
```

**Or skip straight to a fully populated system** (`DemoDataSeeder` creates `admin@test.com`,
which closes `/register` immediately):

```bash
php artisan migrate:fresh --seed
```

## 8. Manual walkthrough that hits every bullet

| Step | Expect |
|---|---|
| Fresh DB, roles seeded, visit `/login` | "Set up the administrator account" link visible |
| `/register` → create admin | lands on `/my-account`, green "Administrator account created" banner |
| Visit `/register` again | → `/login` with a red "Registration is closed" banner; link gone from the login page |
| Visit `/my-account` logged out | → `/login` |
| Visit `/task2` logged out | renders fine — public by design |
| Log in `employee@company1.com` / `password` | → `/my-account`; nav shows **My Account, Attendance Logs, Requests** only; employee record card visible |
| Visit `/login` while logged in | → `/my-account` |
| Type `/employees` in the URL bar as employee | → `/my-account` with a red "You do not have permission" banner |
| `curl -H "Accept: application/json" /employees` with that session | `403` |
| Log in `admin@company1.com` | nav adds **Users, Companies, Employees, Requests**; Companies list shows **only Company One**; no "Add company" button |
| `PUT /companies/{company-two-id}` as that user | 403 / redirect — the policy blocks cross-company writes |
| Log in the admin | nav is **My Account, Users, Companies, Employees**; Companies shows **both**, "Add company" and delete visible |
| Log in `disabled@company1.com` | rejected at the login form |
| Disable a logged-in user in the DB, then click any link | logged out, → `/login` |

## 9. One knock-on effect of decision 4 worth confirming

Because `admin` is created only through the one-time `/register`, I also removed `admin` from
the roles assignable on the `/users` page — otherwise an admin could mint a second admin there
and the "only one admin" rule would be cosmetic. That means **`admin` becomes unassignable
entirely** once the first one exists.

If you'd rather an existing admin *can* promote someone (the "only one" rule then applies to
self-registration only, not to the system), change one line in `UserController::assignableRoles()`:

```php
return $viewer->hasRole('admin')
    ? ['admin', 'company_admin', 'employee']   // <- add 'admin'
    : ['company_admin', 'employee'];
```

Tell me which and I'll build it that way.
