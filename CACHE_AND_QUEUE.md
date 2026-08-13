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
