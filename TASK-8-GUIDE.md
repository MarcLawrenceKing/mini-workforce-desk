# Task 8 — Laravel + Redis: cache & queue (code + explanation)

> Nothing here is applied yet. Paste it yourself, in order.

Checked against your repo before writing: PHP **8.5** with the `redis` extension
already loaded, a Redis server answering on `127.0.0.1:6379` (`redis-cli ping` →
`PONG`), Laravel **13.8**, and `config/cache.php` / `config/queue.php` already
carrying their `redis` blocks. So this task is pure app code — no installs.

---

## 0. The 30-second mental model

**Cache** = _"I already did this expensive work, keep the answer in memory so I
don't redo it."_ Your `/dashboard` runs three `COUNT(*)` queries on every page
load. Cached, it runs them once and serves everyone else from RAM. The hard part
isn't caching — it's **invalidation**: knowing when the stored answer went stale.

**Queue** = _"Do this later, not while the user is waiting."_ Approving a log
shouldn't make the admin wait for an email to send. So you push a small note
("send notification for log #42") into a Redis list, return the response
instantly, and a separate long-running process — the **worker** — pulls notes off
the list and does the slow work.

Redis is just a fast in-memory key–value store that happens to be good at both.

```
                        ┌─────────────────────────┐
  GET /dashboard  ────► │ Cache::tags(['dashboard'])
                        │   ->remember(key, ttl, fn)
                        └───────┬─────────────────┘
                       hit ◄────┤────► miss → 3× COUNT(*) → store in Redis
                                │
  Employee created ─────────────┴──► Observer → tags(['dashboard'])->flush()


  PUT /attendance-logs/42/approve
        │
        ├─ 1. write status=approved to MySQL
        ├─ 2. push SendTimeLogApprovedNotification onto Redis list
        └─ 3. return redirect (admin is done waiting)

  php artisan queue:work redis   ── pops the job ──►  notifications.log + mail
```

---

## 1. Point Laravel at Redis

In `.env`, change these two lines:

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

Leave the `REDIS_*` block alone — it's already correct:

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Make the same two changes in `.env.example` so a fresh clone gets them.

Then:

```powershell
php artisan config:clear
```

Sanity check that Laravel can actually reach Redis:

```powershell
php artisan tinker --execute="Cache::put('hello','world',60); echo Cache::get('hello');"
```

Should print `world`. If it errors, Redis isn't reachable and nothing below will
work.

> **Heads up:** the moment you paste section 2, the app _requires_
> `CACHE_STORE=redis`. `Cache::tags()` throws `BadMethodCallException` on the
> `database` and `file` stores. Do this section first, and don't skip
> `config:clear`.

---

## 2. Cache the dashboard KPIs

Replace the whole of `app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Safety net only. DashboardCacheObserver clears these keys the moment the
     * underlying rows change, so the TTL just stops a forgotten key living forever.
     */
    private const CACHE_TTL_SECONDS = 600;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($request->filled('from'), 'after_or_equal:from'),
            ],
        ]);

        // One cache entry per filter combination — /dashboard and
        // /dashboard?from=2026-01-01 are different questions with different answers.
        $key = sprintf(
            'dashboard:kpis:%s:%s',
            $filters['from'] ?? 'any',
            $filters['to'] ?? 'any',
        );

        // remember() = "give me this key; if it's missing, run the closure,
        // store what it returns, and hand it back".
        $kpis = Cache::tags(['dashboard'])->remember(
            $key,
            self::CACHE_TTL_SECONDS,
            fn () => $this->countKpis($filters),
        );

        return Inertia::render('AdminDashboard', [
            'kpis' => $kpis,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    /**
     * The three counts behind the tiles. Only ever runs on a cache miss.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    private function countKpis(array $filters): array
    {
        $withinCreatedRange = function (Builder $query) use ($filters): void {
            $query
                ->when(
                    $filters['from'] ?? null,
                    fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from),
                )
                ->when(
                    $filters['to'] ?? null,
                    fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to),
                );
        };

        return [
            'employees' => Employee::query()->where($withinCreatedRange)->count(),
            'companies' => Company::query()->where($withinCreatedRange)->count(),
            'users' => User::query()->count(),
        ];
    }
}
```

### What changed, and why

| Before                                       | After                                                             |
| -------------------------------------------- | ----------------------------------------------------------------- |
| Three `COUNT(*)` queries inline in `index()` | Same three queries, moved into `countKpis()`                      |
| Ran on every request                         | `countKpis()` only runs on a **cache miss**                       |
| —                                            | Result stored in Redis under a per-filter key, tagged `dashboard` |

**Why tags?** Because the date filters mean there isn't _one_ key to forget —
there's a key per date range anyone has ever visited. A **tag** is a label you
stick on a group of keys so you can drop the whole group in one call. Tags only
work on tag-aware stores (Redis, Memcached, array) — which is exactly the sort of
thing you switch to Redis for. It's also why your old `CACHE_STORE=database`
would now throw.

**Why `remember()` and not `put()`/`get()`?** `remember()` is the
get-or-compute-and-store pattern in one call. Writing it by hand is three lines
and an easy place to introduce a bug.

---

## 3. Invalidate the cache when data changes

Create `app/Observers/DashboardCacheObserver.php`:

```php
<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the cached /dashboard KPI counts honest.
 *
 * Attached to Company, Employee and User via #[ObservedBy]. Any row added,
 * removed or restored drops every key tagged 'dashboard', so the next visit
 * recounts from the database.
 */
class DashboardCacheObserver
{
    public function created(Model $model): void
    {
        $this->flush();
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function restored(Model $model): void
    {
        $this->flush();
    }

    public function forceDeleted(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        Cache::tags(['dashboard'])->flush();
    }
}
```

Now attach it to the three models. Each needs two `use` lines and one attribute.

**`app/Models/Employee.php`**

```php
use App\Observers\DashboardCacheObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
```

```php
#[Fillable([
    'user_id', 'company_id', 'employee_no',
    'first_name', 'middle_name', 'last_name',
])]
#[ObservedBy(DashboardCacheObserver::class)]
class Employee extends Model
```

**`app/Models/Company.php`** — same two `use` lines, then:

```php
#[Fillable(['name', 'is_active', 'rate_per_hr'])]
#[ObservedBy(DashboardCacheObserver::class)]
class Company extends Model
```

**`app/Models/User.php`** — same two `use` lines, then:

```php
#[Fillable(['company_id', 'name', 'email', 'username', 'password', 'is_disabled'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy(DashboardCacheObserver::class)]
class User extends Authenticatable implements LaratrustUser
```

### Why there's no `updated()` method

Editing an employee's name doesn't change _how many_ employees exist, so there's
nothing to invalidate. The rule is: **hook the events that can change the cached
number.**

If a KPI ever becomes condition-dependent — "active employees", "pending logs" —
add `updated()`, because then an edit _can_ move the number.

Getting this wrong in the safe direction (flushing too often) costs you a query.
Getting it wrong the other way shows users stale numbers, which is the bug nobody
notices for three weeks. When in doubt, over-invalidate.

`deleted` matters because `Employee` uses `SoftDeletes` and the default query
excludes trashed rows — a soft delete really does change the count. `restored`
and `forceDeleted` are the other two ends of that.

### Why `#[ObservedBy]` and not `AppServiceProvider`

Both work. The attribute keeps the wiring visible on the model itself rather than
buried in a provider, and it's the current Laravel idiom — this repo already uses
`#[Fillable]` and `#[Hidden]` the same way.

---

## 4. Prove the cache works

```powershell
php artisan config:clear
php artisan serve
```

Load `/dashboard` in the browser (log in as an admin — the route is
`role:admin`). Then in a second terminal:

```powershell
redis-cli --scan --pattern "*dashboard*"
// (redis box 1 & git bash) redis-cli -n 1 --scan --pattern "*dashboard*"
```

You'll see your KPI key plus the tag bookkeeping keys. Keys carry two prefixes in
Redis (`REDIS_PREFIX`, then `CACHE_PREFIX`), which is why you scan by pattern
rather than guessing the exact name.

Check the TTLs:

```powershell
redis-cli --scan --pattern "*dashboard*" | ForEach-Object { redis-cli ttl $_ }
// (redis box 1 & git bash) redis-cli -n 1 --scan --pattern "*dashboard*" | while read -r key; do echo "$(redis-cli -n 1 ttl "$key" </dev/null) <- $key"; done
```

**Now the real proof.** Reload `/dashboard` a few times, then create a new
employee at `/employees`, then re-run the scan — the keys are **gone**. Reload the
dashboard and they come back with the new count.

Watch cache reads and writes live:

```powershell
redis-cli monitor
```

Load `/dashboard` twice with `monitor` running: the first load shows `GET` (miss)
then `SETEX` (store), the second shows only `GET`. That's the cache earning its
keep.

Manual poking, if you like:

```powershell
php artisan tinker
>>> Cache::tags(['dashboard'])->get('dashboard:kpis:any:any')
>>> Cache::tags(['dashboard'])->flush()
```

---

## 5. Add a `notifications` log channel

You have no notification feature yet, so the job writes to a dedicated log file —
easy to point at as proof, and not drowned out by everything else in
`laravel.log`.

In `config/logging.php`, inside `'channels' => [`, add:

```php
        'notifications' => [
            'driver' => 'single',
            'path' => storage_path('logs/notifications.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],
```

---

## 6. Create the job

```powershell
php artisan make:job SendTimeLogApprovedNotification
```

Then replace `app/Jobs/SendTimeLogApprovedNotification.php` with:

```php
<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued when a company_admin approves or rejects an attendance log.
 *
 * `implements ShouldQueue` is the whole trick: without it Laravel runs the job
 * inline, with it the job is serialised onto the Redis queue and the HTTP
 * request returns straight away.
 */
class SendTimeLogApprovedNotification implements ShouldQueue
{
    use Queueable;

    /** Retry a failing job twice more, 10s apart, before it lands in `queue:failed`. */
    public int $tries = 3;

    public int $backoff = 10;

    /**
     * The model is serialised as just its id and re-fetched by the worker, so
     * the notification reflects the row as it is when the job actually runs.
     *
     * @param  string  $decision  'approved' or 'rejected'
     */
    public function __construct(
        public AttendanceLog $attendanceLog,
        public string $decision,
    ) {}

    public function handle(): void
    {
        $log = $this->attendanceLog->loadMissing(['employee.user', 'approver']);
        $recipient = $log->employee?->user?->email;

        $summary = sprintf(
            'Attendance log #%d for %s on %s was %s%s.',
            $log->id,
            $log->employee?->full_name ?? 'an unknown employee',
            $log->date->format('Y-m-d'),
            $this->decision,
            $this->decision === 'approved'
                ? ' by '.($log->approver?->name ?? 'a company admin')
                : ($log->reject_reason ? ' — reason: '.$log->reject_reason : ''),
        );

        // Stand-in for a real notification feature: one line per job in
        // storage/logs/notifications.log.
        Log::channel('notifications')->info($summary, [
            'attendance_log_id' => $log->id,
            'decision' => $this->decision,
            'recipient' => $recipient,
        ]);

        // MAIL_MAILER=log, so this "email" is dumped into storage/logs/laravel.log
        // instead of being sent. Swap the mailer and it becomes a real email.
        if ($recipient) {
            Mail::raw($summary, fn (Message $message) => $message
                ->to($recipient)
                ->subject('Your attendance log was '.$this->decision));
        }
    }
}
```

### Things worth understanding here

**`implements ShouldQueue`** is the only thing separating "run now, caller waits"
from "run later on a worker". Drop it and `::dispatch()` executes inline.

**The `Queueable` trait** bundles `Dispatchable` (gives you `::dispatch()`),
`InteractsWithQueue`, `SerializesModels`, and the bus `Queueable`.

**`SerializesModels`** is why passing a whole `AttendanceLog` into the constructor
is safe. Laravel doesn't shove the model's attributes into Redis — it stores the
class name and the primary key, and the worker re-fetches from MySQL. Two
consequences: the job always sees fresh data, and if the row is deleted before the
worker gets to it, the job is quietly discarded instead of exploding.

**`Mail::raw()`** sends a plain-text email without needing a Mailable class or a
Blade view — perfect for a stand-in. With `MAIL_MAILER=log` (your current
setting) the rendered email is written to `storage/logs/laravel.log` rather than
sent anywhere.

**`$tries` / `$backoff`** — jobs fail (network blips, a downstream API down).
Three attempts with a 10-second gap, then the job is recorded in the `failed_jobs`
table for you to inspect with `php artisan queue:failed`.

---

## 7. Dispatch on approve and reject

In `app/Http/Controllers/AttendanceLogController.php`, add the import:

```php
use App\Jobs\SendTimeLogApprovedNotification;
```

### Approve

The green ✓ button hits the dedicated `approve()` method. Add one line before the
`return`:

```php
        $attendanceLog->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'reject_reason' => null,
        ]);

        // Queued, not run inline — the admin gets their redirect immediately.
        SendTimeLogApprovedNotification::dispatch($attendanceLog, 'approved');

        return back()->with('success', 'Attendance log approved.');
```

### Reject

Rejection has no dedicated route — it goes through the Edit dialog, which lands in
`update()`. Two edits there.

First, capture the old status right after the two asserts at the top:

```php
    public function update(Request $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $this->assertManager($request);
        $this->assertLogInScope($request, $attendanceLog);

        $previousStatus = $attendanceLog->status;
```

Then, after the `$attendanceLog->update([...])` call and before the `return`:

```php
        // Only on a real transition — re-saving an already-approved log with no
        // status change shouldn't re-notify the employee.
        if ($validated['status'] !== $previousStatus
            && in_array($validated['status'], ['approved', 'rejected'], true)) {
            SendTimeLogApprovedNotification::dispatch($attendanceLog, $validated['status']);
        }

        return back()->with('success', 'Attendance log updated.');
```

`$previousStatus` **must** be read before `update()` runs, otherwise you're
comparing the new value to itself and the condition never fires.

---

## 8. Run the worker and prove the job runs

The best demo is seeing the job **sitting in Redis** before anything consumes it.

**Terminal 1** — app only, no worker:

```powershell
php artisan serve
```

Log in as a `company_admin`, go to `/attendance-logs`, and approve a pending log
with the green ✓. (The button only appears when the log has both a time in and a
time out.)

**Terminal 2** — look inside the queue:

```powershell
redis-cli LLEN laravel-database-queues:default
redis-cli LRANGE laravel-database-queues:default 0 -1
```

`LLEN` returns `1`. `LRANGE` prints the raw JSON payload — you can read the job
class name and the serialised model id right there. That is literally the "put
notification job into Redis" step, visible.

**Terminal 2** — now start the worker:

```powershell
php artisan queue:work redis --verbose
```

It prints `Processing` then `Processed` for the job, and `LLEN` drops back to `0`.

**Terminal 3** — the proof:

```powershell
Get-Content storage/logs/notifications.log -Tail 5
Get-Content storage/logs/laravel.log -Tail 40
```

`notifications.log` has your summary line. `laravel.log` has the rendered email,
because `MAIL_MAILER=log`.

Or stream everything live with the tool already installed in this repo:

```powershell
php artisan pail
```

### Worker commands worth knowing

```powershell
php artisan queue:work redis --verbose   # long-lived; fast, holds code in memory
php artisan queue:listen redis           # restarts per job; slower, picks up code edits
php artisan queue:restart                # tell running workers to exit after the current job
php artisan queue:failed                 # jobs that exhausted all tries
php artisan queue:retry all              # re-queue every failed job
php artisan queue:flush                  # discard failed jobs
```

`composer dev` already runs `php artisan queue:listen` alongside `serve` and
`vite`, so day-to-day you get a worker for free.

### The #1 beginner gotcha

`queue:work` loads your code **once** at boot and keeps it in memory. Edit the job
file and the running worker keeps executing the _old_ version — you'll change the
log message, re-approve, and see the old text, and lose twenty minutes to it.

Always `php artisan queue:restart` (or Ctrl-C and restart) after touching job
code. `queue:listen` avoids this by booting fresh per job, at the cost of speed —
which is why it's the dev default and `queue:work` is the production one.

---

## 9. Optional: lock it in with tests

Create `tests/Feature/RedisCacheQueueTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendTimeLogApprovedNotification;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RedisCacheQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['laratrust.cache.enabled' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);
        $user->addRole($role);

        return $user->fresh();
    }

    public function test_dashboard_counts_are_cached_and_dropped_when_a_company_is_added(): void
    {
        $admin = $this->user('admin');
        Company::create(['name' => 'First', 'is_active' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->assertSame(
            1,
            Cache::tags(['dashboard'])->get('dashboard:kpis:any:any')['companies'],
        );

        // Creating a company must invalidate the cached counts.
        Company::create(['name' => 'Second', 'is_active' => true]);

        $this->assertNull(Cache::tags(['dashboard'])->get('dashboard:kpis:any:any'));

        $this->actingAs($admin)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('kpis.companies', 2));
    }

    public function test_approving_a_log_queues_the_notification_job(): void
    {
        Queue::fake();

        $company = Company::create(['name' => 'Own Co', 'is_active' => true, 'rate_per_hr' => 100]);
        $admin = $this->user('company_admin', $company);
        $employee = Employee::create([
            'company_id' => $company->id,
            'employee_no' => 'E-1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $log = AttendanceLog::create([
            'employee_id' => $employee->id,
            'date' => today()->toDateString(),
            'log_in_time' => '09:00:00',
            'log_out_time' => '17:00:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->put("/attendance-logs/{$log->id}/approve")
            ->assertSessionHas('success');

        Queue::assertPushed(
            SendTimeLogApprovedNotification::class,
            fn ($job) => $job->attendanceLog->id === $log->id && $job->decision === 'approved',
        );
    }
}
```

```powershell
php artisan test
```

`phpunit.xml` pins `CACHE_STORE=array` and `QUEUE_CONNECTION=sync`, so the suite
needs no Redis running. The `array` store supports tags, so the cache test works
unchanged. `Queue::fake()` swaps the queue for a recorder — the job is never
actually executed, you just assert it was _pushed_.

---

## 10. README section

Append this to `README.md` — this is the deliverable the task asks for.

````markdown
## Redis: cache & queue

Redis backs two things in this app: the cached `/dashboard` KPI counts, and the
background job queue. Both are configured through `.env`:

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

A Redis server must be running (`redis-cli ping` → `PONG`). Run
`php artisan config:clear` after changing `.env`.

### Cache — dashboard KPIs

`DashboardController::index()` wraps its three `COUNT(*)` queries in
`Cache::tags(['dashboard'])->remember(...)`, so the counts are computed once and
served from Redis afterwards.

|             |                                                                  |
| ----------- | ---------------------------------------------------------------- |
| Key pattern | `dashboard:kpis:{from}:{to}`                                     |
| Examples    | `dashboard:kpis:any:any`, `dashboard:kpis:2026-01-01:2026-01-31` |
| `any`       | placeholder for an omitted date filter                           |
| Tag         | `dashboard`                                                      |
| TTL         | 600s — a safety net; invalidation is event-driven                |

The date filters mean there is one key **per filter combination**, not one key
overall — so entries are grouped under the `dashboard` tag and dropped together.
Tags require a tag-aware cache store, which is one of the reasons this project
uses Redis rather than the `database` store.

In Redis the keys carry two prefixes (`REDIS_PREFIX`, then `CACHE_PREFIX`); find
them with `redis-cli --scan --pattern "*dashboard*"`.

**Invalidation.** `App\Observers\DashboardCacheObserver` is attached to
`Company`, `Employee` and `User` via `#[ObservedBy]`. On `created`, `deleted`,
`restored` and `forceDeleted` it calls `Cache::tags(['dashboard'])->flush()`,
clearing every filter combination at once. There is deliberately no `updated()`
hook: editing a row does not change how many rows exist. If a KPI ever becomes
condition-dependent (e.g. "active employees"), add `updated()` too.

### Queue — approval notifications

Approving or rejecting an attendance log should not make the admin wait on a
notification, so the work is pushed onto Redis and the response returns
immediately:

```
company_admin clicks Approve → status written to MySQL
                             → SendTimeLogApprovedNotification pushed to Redis
                             → response returned

queue worker → pops the job → writes storage/logs/notifications.log + sends mail
```

|                 |                                                                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Job             | `App\Jobs\SendTimeLogApprovedNotification`                                                                                     |
| Dispatched from | `AttendanceLogController::approve()`, and `update()` when the status transitions to `approved`/`rejected`                      |
| Redis key       | `laravel-database-queues:default` (a Redis list)                                                                               |
| Retries         | 3 attempts, 10s backoff                                                                                                        |
| Output          | `storage/logs/notifications.log`, plus a `Mail::raw()` message (written to `storage/logs/laravel.log` while `MAIL_MAILER=log`) |

The job only stores the attendance log's **id**; the worker re-fetches the model,
so the notification always reflects the current row.

**Running a worker**

```bash
php artisan queue:work redis --verbose   # long-lived worker
php artisan queue:listen redis           # reloads code between jobs (dev)
php artisan queue:restart                # after deploying changed job code
php artisan queue:failed                 # inspect failures
composer dev                             # server + queue:listen + vite together
```

`queue:work` boots your code once and keeps it in memory — restart it after
editing a job, or it will keep running the old version.

**Inspecting the queue**

```bash
redis-cli LLEN laravel-database-queues:default    # jobs waiting
redis-cli LRANGE laravel-database-queues:default 0 -1
redis-cli monitor                                 # live command stream
php artisan pail                                  # live log stream
```

Failed jobs are recorded in the `failed_jobs` database table, not in Redis.

### Testing

`phpunit.xml` pins `CACHE_STORE=array` and `QUEUE_CONNECTION=sync`, so the test
suite needs no Redis. `tests/Feature/RedisCacheQueueTest.php` covers cache
invalidation and job dispatch (via `Queue::fake()`).
````

---

## 11. Order to run everything

1. Section 1 — edit `.env` and `.env.example`, then `php artisan config:clear`
2. Paste sections 2, 3, 5, 6, 7
3. `php artisan serve` in one terminal
4. Approve a log **with no worker running**; confirm
   `redis-cli LLEN laravel-database-queues:default` → `1`
5. Start `php artisan queue:work redis --verbose`; watch it drain, then check
   `storage/logs/notifications.log`
6. Section 4 — prove the cache fills and flushes
7. `php artisan test`
8. Section 10 — paste the README block

---

## 12. Task checklist

| Requirement                                         | Where                                                                  |
| --------------------------------------------------- | ---------------------------------------------------------------------- |
| Redis cache for company dashboard counts            | §2 — `Cache::tags(['dashboard'])->remember()` in `DashboardController` |
| Invalidate on changes                               | §3 — `DashboardCacheObserver` on `Company`, `Employee`, `User`         |
| Job `SendTimeLogApprovedNotification`               | §6                                                                     |
| Dispatched on approve/reject                        | §7 — `approve()` and `update()`                                        |
| `QUEUE_CONNECTION=redis`                            | §1                                                                     |
| Run queue worker                                    | §8 — `php artisan queue:work redis`                                    |
| Prove job runs (log/mail)                           | §8 — `storage/logs/notifications.log` + `laravel.log`                  |
| Document Redis usage in README (cache keys + queue) | §10                                                                    |

---

## 13. Gotchas, collected

| Symptom                                                             | Cause                                                                                                |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `BadMethodCallException: This cache store does not support tagging` | `CACHE_STORE` is still `database` or `file`. Set it to `redis` and `config:clear`.                   |
| `.env` change had no effect                                         | Config is cached. `php artisan config:clear`.                                                        |
| Job edits don't take effect                                         | `queue:work` is running old code. `php artisan queue:restart`.                                       |
| Job never runs, `LLEN` keeps climbing                               | No worker is running.                                                                                |
| Job runs instantly, nothing in Redis                                | The job class is missing `implements ShouldQueue`, or `QUEUE_CONNECTION` is still `database`/`sync`. |
| Dashboard shows stale counts                                        | An event that changes the count isn't hooked in the observer.                                        |
| Reject never notifies                                               | `$previousStatus` was read _after_ `update()`.                                                       |
| `Connection refused [tcp://127.0.0.1:6379]`                         | Redis server isn't running.                                                                          |
