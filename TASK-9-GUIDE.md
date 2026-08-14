# Task 9 — API routes + Sanctum + Axios

**Status: built, tested, and verified over real HTTP.** This document is both the
explanation (§0, for the concepts you hadn't met yet) and the record of what was
actually done (§1 onward).

Quick links: [`routes/api.php`](routes/api.php) ·
[`Api/TimeLogController.php`](app/Http/Controllers/Api/TimeLogController.php) ·
[`lib/api.js`](resources/js/lib/api.js) ·
[`useApi.js`](resources/js/Composables/useApi.js) ·
[Postman collection](docs/postman/mini-workforce-desk.postman_collection.json) ·
[README API section](README.md#api-task-9)

---

## 0. The concepts, in plain English

### 0.1 What is an "API route" and why do we need one?

Before this task, your app talked to itself like this:

```
Browser  ──"give me the attendance page"──►  Laravel
Browser  ◄──"here is a whole PAGE (Inertia + Vue component + data)"── Laravel
```

Every action fetched a page. Inertia does it cleverly, but conceptually it is
still "here is the next page". An **API route** is different:

```
Browser  ──"give me the time logs as data"──►  Laravel
Browser  ◄──  [{"id":1,"date":"2026-08-13",...}]  ──  Laravel
```

No page. No HTML. Just **JSON** — raw data, and the caller decides what to do
with it.

Why bother when Inertia already worked?
- A **phone app** can't consume Inertia pages. It can consume JSON.
- Small actions (approve one row, delete one row) shouldn't re-render a page.
- It's how systems integrate with each other.

So: `routes/web.php` = pages for humans. `routes/api.php` = data for programs.

### 0.2 Why an API needs a different kind of login

When you log in through a browser, Laravel puts a **session cookie** in it. The
browser then attaches that cookie to every request automatically. You never think
about it — invisible plumbing.

Postman is not a browser. A mobile app is not a browser. They have no cookie jar
and no login form. They need a different way to say "it's me". That way is a
**token**.

### 0.3 What is a token?

A token is just **a long random string that stands in for your password**.

```
1|k0qlocuwUwUmdbhbuki4TaqBUGMvk6FbfTDcV4S93da5872b
```

You log in **once** with email + password, the server hands you that string, and
from then on you send it with every request:

```
Authorization: Bearer 1|k0qlocuwUwUmdbhbuki4TaqBUGMvk6FbfTDcV4S93da5872b
```

The server looks it up, finds "this belongs to user #2", and treats the request
as user #2. That's the whole idea.

Why not just send the password every time?
- A token can be **revoked** (delete one row) without changing your password.
- You can hold **many** — one per device — and kill just the stolen one.
- Your password never travels again after the first login.

It's a **hotel key card**. Show ID at the desk once (password), get a card
(token), the card opens doors until checkout (revoke). Losing the card doesn't
leak your ID.

### 0.4 What is Sanctum?

**The official Laravel package that does the token bookkeeping for you.** It
gives you a `personal_access_tokens` table, `$user->createToken()`, and an
`auth:sanctum` middleware that reads the `Authorization` header, finds the user,
and returns **401** if the token is missing or invalid. Without it you'd
hand-write all of that.

> Sanctum vs Passport vs JWT, one line each so the words stop being scary:
> **Sanctum** — simple random-string tokens in your database. Right for this app.
> **Passport** — a full OAuth2 server, for third-party "Log in with MyApp" consent
> screens. Overkill.
> **JWT** — self-contained signed tokens, no database lookup, hard to revoke. Not needed.

### 0.5 The one confusing bit: Sanctum has TWO modes

Sanctum authenticates a request in two different ways and picks automatically:

| Mode | Who uses it | How identity is proven |
|---|---|---|
| **Stateful (cookie)** | our Vue UI, in a browser already logged in at `/login` | the normal Laravel **session cookie** + a CSRF header |
| **Token (Bearer)** | Postman, Insomnia, curl, mobile apps | `Authorization: Bearer <token>` |

Both hit the same routes, both pass through `auth:sanctum`, both end up with
`$request->user()` populated. Sanctum asks: *"is this from my own frontend (known
host, has a session cookie)? use the session. Otherwise look for a Bearer token."*

**What we built:**

- **The Vue UI uses cookie mode only.** No token in JavaScript, no
  `localStorage`, no `Authorization` header from Vue. Simpler *and* safer: a
  token in `localStorage` can be stolen by any XSS bug, whereas the session
  cookie is `HttpOnly` — JavaScript cannot read it, so it cannot leak it.
- **Postman uses a Bearer token** from `POST /api/login`. That endpoint exists
  purely for clients with no browser session.

### 0.6 What is Axios?

**A small JavaScript library for making HTTP requests from the browser.**

```js
const response = await axios.get('/api/time-logs')
console.log(response.data)   // the JSON
```

The browser has a built-in `fetch()`; people prefer Axios because it parses JSON
for you, lets you set **default headers once**, has **interceptors** (a hook on
every response — ideal for "if it's a 401, go to the login page"), and **throws**
on error statuses instead of quietly succeeding.

**Axios vs Inertia's `useForm`** — you use both in this app now:

| | Inertia `useForm` | Axios |
|---|---|---|
| Server replies with | a page or redirect | JSON |
| Screen updates by | swapping Inertia page props | you writing `rows.value = data` |
| Loading state | `form.processing`, given to you | `loading.value`, you manage it |
| Validation errors | `form.errors`, given to you | you read `error.response.data.errors` |
| Good for | full forms, navigation | in-place row actions, filters |

The task wanted the **manual** version — loading flag, error mapping, 401/403
handling — because that skill transfers to any frontend.

### 0.7 What are Postman / Insomnia?

**A GUI for firing HTTP requests by hand.** Type a URL, pick the method, fill in
headers and a JSON body, hit Send, read the raw response and status code.

Its value: it tests your API **with your Vue code out of the picture**. Postman
works but the UI doesn't → the bug is in the frontend. Postman fails too → the bug
is in Laravel. That halves your debugging surface.

§9 is a click-by-click walkthrough for your first time.

### 0.8 The status codes

| Code | Means | Here |
|---|---|---|
| **200** | OK | here's your data |
| **201** | Created | the POST made a new log |
| **204** | No Content | deleted — **empty body is success** |
| **401 Unauthorized** | *"I don't know who you are"* | no/expired token → **go to `/login`** |
| **403 Forbidden** | *"I know you, and no"* | employee trying to approve → **show a message** |
| **422 Unprocessable** | *"understood, but the data is invalid"* | `time_out` before `time_in` → **errors under the fields** |
| **419** | CSRF token missing/expired | browser writes without the XSRF header |
| **404 / 429 / 500** | no such record / too many logins / server crash | |

401 vs 403 is the classic interview question. **401 = who are you? 403 = I know
you, no.** Re-logging-in fixes a 401 and never fixes a 403.

### 0.9 The whole picture

```
  ┌──────────── Postman / curl (no browser session) ──────────┐
  │  POST /api/login {email, password, device_name}           │
  │       ◄── {"token": "1|k0qloc…"}                          │
  │  GET  /api/time-logs                                      │
  │       Authorization: Bearer 1|k0qloc…                     │
  └───────────────────────────┬───────────────────────────────┘
                              │
                    ┌─────────▼───────────────┐
                    │  routes/api.php         │
                    │   auth:sanctum          │──401 if unknown──►
                    │   active                │──403 if disabled─►
                    │   permission:…          │──403 if not allowed──►
                    └─────────┬───────────────┘
                              │
              ┌───────────────▼──────────────────┐
              │ Api\TimeLogController            │
              │  JSON only, never Inertia        │
              │  Store/UpdateTimeLogRequest ─────┼── 422 + {errors}
              │  TimeLogResource ────────────────┼── one shape for web + API
              └───────────────┬──────────────────┘
                              │
  ┌───────────────────────────▼───────────────────────────────┐
  │  Vue + Axios (resources/js/lib/api.js)                    │
  │   • session cookie sent by the browser (HttpOnly)         │
  │   • withXSRFToken + X-Requested-With attached by Axios    │
  │   • interceptor: 401 → /login, 419 → reload               │
  │   • useApi(): loading, errors (422), message (403/404)    │
  └───────────────────────────────────────────────────────────┘
```

---

## 1. Sanctum installed

```bash
php artisan install:api      # composer require laravel/sanctum + routes/api.php + migration
php artisan migrate          # creates personal_access_tokens
```

Installed **laravel/sanctum v4.3.3**. The command printed:

```
WARN  Unable to automatically add API route definition to bootstrap/app.php.
```

That's expected — your `bootstrap/app.php` is customised, so it wouldn't touch
it. Registering the route file by hand was step 3.

## 2. `User` can hold tokens

[`app/Models/User.php`](app/Models/User.php):

```php
use Laravel\Sanctum\HasApiTokens;

// HasApiTokens is Task 9: it adds createToken()/tokens() so a user can hold
// personal access tokens for the /api routes.
use HasApiTokens, HasFactory, HasRolesAndPermissions, Notifiable;
```

## 3. Middleware wiring

[`bootstrap/app.php`](bootstrap/app.php) — two edits:

```php
->withRouting(
    web: __DIR__ . '/../routes/web.php',
    // Task 9: every route in here is prefixed with /api
    api: __DIR__ . '/../routes/api.php',
    …
)
->withMiddleware(function (Middleware $middleware): void {
    // Task 9: lets our own Vue frontend authenticate against /api/* with the
    // session cookie it already has, instead of carrying a bearer token in JS.
    $middleware->statefulApi();
```

`statefulApi()` is what makes cookie mode work. Without it, the Vue Axios calls
would 401 even though you're logged in.

**Nothing needed for JSON errors** — `shouldRenderJsonWhen(… is('api/*'))` at
[bootstrap/app.php:37](bootstrap/app.php#L37) already forces JSON error bodies,
and the 403-redirect block below it already exempts `api/*`. Past-you saved a
step.

### `.env` — the setting that isn't optional

```dotenv
SANCTUM_STATEFUL_DOMAINS=localhost:8000,127.0.0.1:8000,localhost,127.0.0.1,::1
```

Sanctum's built-in default list contains `localhost` and `127.0.0.1:8000` but
**not `localhost:8000`** — which is exactly where `php artisan serve` runs. A
host that isn't on this list is treated as a third party, so cookie mode is
skipped and every Vue call 401s. Added to both `.env` and `.env.example`.

## 4. Token endpoints

[`app/Http/Controllers/Api/AuthController.php`](app/Http/Controllers/Api/AuthController.php)

| Route | Does |
|---|---|
| `POST /api/login` | validates email + password + `device_name`, refuses disabled accounts, returns `{token, user}` |
| `GET /api/user` | who this token belongs to, plus roles and permissions |
| `POST /api/logout` | `currentAccessToken()->delete()` — revokes **only** the calling token |

Two deliberate choices:

- **Bad credentials return 422, not 401.** The request was understood; the data
  was wrong. It comes back in the same `{message, errors}` envelope every other
  validation failure uses, so a client needs one error handler, not two.
- **Rate limited** to 5 attempts per email+IP, mirroring the web `LoginRequest`.

`plainTextToken` is shown **exactly once** — only a hash is stored.

## 5. The routes

[`routes/api.php`](routes/api.php)

| Method | URL | Controller | Returns |
|---|---|---|---|
| GET | `/api/time-logs` | `index` | 200 + list (`?month=`, `?status=`, `?employee_id=`) |
| POST | `/api/time-logs` | `store` | 201 + the created log |
| GET | `/api/time-logs/{id}` | `show` | 200 + one log |
| PUT/PATCH | `/api/time-logs/{id}` | `update` | 200 + the updated log |
| DELETE | `/api/time-logs/{id}` | `destroy` | 204 — hard delete |
| PUT | `/api/time-logs/{id}/approve` | `approve` | 200 + the approved log |

`apiResource` gives the first five (it's `resource` minus the HTML form routes).

**Every name is prefixed `api.`** — and that matters more than it looks:

```php
Route::name('api.')->group(function () {
    Route::post('/login', …)->name('login');
```

Without the prefix, `->name('login')` here **overwrites the web route of the same
name**, and `redirectGuestsTo(fn () => route('login'))` starts sending browsers to
`POST /api/login`. Four existing tests caught this immediately. See §11.

## 6. Controllers, requests, resource

The new [`Api\TimeLogController`](app/Http/Controllers/Api/TimeLogController.php)
is a **sibling** of the existing `AttendanceLogController`, not a replacement —
that one returns `Inertia::render()` and `back()`, which is exactly wrong for an
API. The web UI is untouched and still works.

To stop the two drifting apart, the shared parts were extracted, and **both**
controllers now use them:

| File | Holds |
|---|---|
| [`TimeLogRequest`](app/Http/Requests/TimeLogRequest.php) (abstract) | the shared rules, the approver whitelist, and `toAttributes()` (form fields → database columns) |
| [`StoreTimeLogRequest`](app/Http/Requests/StoreTimeLogRequest.php) | create rules + `authorize()` |
| [`UpdateTimeLogRequest`](app/Http/Requests/UpdateTimeLogRequest.php) | update rules + scope check |
| [`TimeLogResource`](app/Http/Resources/TimeLogResource.php) | the one JSON shape, used by the Inertia pages *and* the API |
| [`NotifiesTimeLogDecision`](app/Http/Controllers/Concerns/NotifiesTimeLogDecision.php) | Task 8's queued notification, fired on the same condition from both |

`AttendanceLogController::store()` went from 30 lines to 5. Its behaviour is
unchanged — the same tests still pass.

**Authorisation, identical to the web UI:**

```
company_admin   read + write, own company only
employee        read own logs only; every write is 403
admin           holds no attendance-logs.view permission → 403 on everything
```

Company scoping is enforced by reusing the existing `visibleTo()` scopes, so a
guessed id from another company is a **403**, not a quiet edit.

### Two behaviour improvements while extracting

Both make the API's errors more useful, and the web UI inherits them:

1. **Naming an employee from another company** used to be a `404` (from
   `findOrFail`). It's now a **422** with `errors.employee_id` — "That employee is
   not part of your company." A validation problem should read like one.
2. **Moving a log to a different employee** used to be `abort(422)` with a bare
   message. It's now a real validation error on `employee_id`, so a client can
   show it under the field.

Likewise `approve` on a non-pending log now throws a `ValidationException` rather
than `abort(422, …)`, so **every** 422 in the API carries the same
`{message, errors}` envelope.

## 7. Axios on the frontend — cookie auth

```bash
npm install axios
```

**How cookie auth works, in one paragraph.** You log in at `/login` as always.
Laravel sets two cookies: the session cookie (`HttpOnly`, JS can't read it) and
`XSRF-TOKEN` (readable on purpose). The browser attaches the session cookie to
every same-origin request **by itself** — no code needed. The only thing you must
do is echo `XSRF-TOKEN` back as a header on writes, and Axios does that with one
flag.

### [`resources/js/lib/api.js`](resources/js/lib/api.js)

```js
const api = axios.create({
    baseURL: "/api",

    // ── These two lines ARE the authentication ────────────────────────
    withCredentials: true,  // send cookies, including the session cookie
    withXSRFToken: true,    // read the XSRF-TOKEN cookie, resend as X-XSRF-TOKEN

    headers: {
        Accept: "application/json",            // "answer with JSON, never HTML"
        "X-Requested-With": "XMLHttpRequest",  // flips expectsJson() to true
    },
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (status === 401) router.visit("/login");   // who are you?
        if (status === 419) window.location.reload(); // CSRF expired
        return Promise.reject(error);                 // 403/422 handled per-screen
    },
);
```

Worth understanding rather than copying:

- **`withXSRFToken: true`** — Laravel still enforces CSRF on session-authenticated
  writes. **Leave this out and every POST/PUT/DELETE returns 419.** Verified: see
  §11.
- **`X-Requested-With`** — guarantees JSON error bodies instead of an HTML redirect.
- **No token anywhere.** That's the design, not an omission.

### [`resources/js/Composables/useApi.js`](resources/js/Composables/useApi.js)

The loading/error bookkeeping, written once:

```js
const { loading, errors, message, call, clear } = useApi();
```

- `loading` → bind to `:loading` / `:disabled`; reset in a `finally`, so a button
  can never stay stuck spinning.
- `errors` → the 422 `errors` object, keyed by field.
- `message` → a one-line banner for 403 / 404 / 500.
- 401 and 419 are handled in the interceptor, so `useApi` deliberately stays
  quiet on them — flashing a banner at someone who's being redirected is noise.

### `vite.config.js`

Added a `@` → `resources/js` alias so pages can `import { useApi } from "@/Composables/useApi"`.
Existing relative imports keep working.

## 8. What the UI does now

**[AdminIndex.vue](resources/js/Pages/AttendanceLogs/AdminIndex.vue)** — Axios for
the row-level actions, Inertia for the full form. That split is the point: a form
is a page-shaped action, approving one row is a data-shaped one.

| Action | How | Demonstrates |
|---|---|---|
| Month arrows | `GET /time-logs?month=` | loading spinner, no page reload |
| **Approve** | `PUT /time-logs/{id}/approve` | per-row spinner, patches just that row |
| **Reject** (new) | `PUT /time-logs/{id}` with `status=rejected` | **422 field errors** under the textarea |
| **Delete** (new) | `DELETE /time-logs/{id}` | 204, row spliced out of the table |
| Create / Edit dialog | Inertia `useForm` — unchanged | the contrast |

The Reject dialog is the clearest demonstration: submit with an empty reason and
the API answers 422 with `errors.reject_reason`, which `useApi` puts in `errors`
and the dialog renders under the field — exactly what Inertia's `useForm` does
for you automatically, only here you can see the wiring.

**[EmployeeIndex.vue](resources/js/Pages/AttendanceLogs/EmployeeIndex.vue)** —
month navigation moved to Axios (the API already scopes the result to your own
logs, and `meta.worked_minutes` lets the salary card recalculate for free). Check
in / check out stay on Inertia, because the API is read-only for an employee by
design.

Both pages show a red `Message` banner bound to `message` for 403/404.

## 9. Postman — your first time, click by click

**Step 1 — install.** [postman.com/downloads](https://www.postman.com/downloads/).
It asks you to sign in; you can click **"Skip and go to the app"** at the bottom.
No account needed for local testing.

**Step 2 — start the app.**
```bash
composer dev
```
Leave it running. The API is at `http://localhost:8000/api`.

**Step 3 — import the collection.** Click **Import** (top-left), drag in
`docs/postman/mini-workforce-desk.postman_collection.json`, confirm. A folder
*Mini Workforce Desk — API (Task 9)* appears in the sidebar with everything
pre-built. You could stop here and click through — but do step 4 once so it isn't
magic.

**Step 4 — one request by hand.**

1. **New → HTTP Request**
2. Method dropdown → **POST**
3. URL → `http://localhost:8000/api/login`
4. **Headers** tab → two rows:
   | Key | Value |
   |---|---|
   | `Accept` | `application/json` |
   | `Content-Type` | `application/json` |
5. **Body** tab → **raw** radio → change the dropdown on the right from *Text* to
   **JSON** → paste:
   ```json
   { "email": "admin@company1.com", "password": "password", "device_name": "postman" }
   ```
6. **Send.** Status **200 OK**, and the body has your token:
   ```json
   { "token": "1|k0qlocuwUwUmdbhbuki4TaqBUGMvk6FbfTDcV4S93da5872b", "user": { … } }
   ```
7. **Copy the token string.**

**Step 5 — use it.**

1. New request → **GET** → `http://localhost:8000/api/time-logs`
2. **Headers** → `Accept: application/json`
3. **Authorization** tab → Type → **Bearer Token** → paste the token
4. **Send** → **200 OK** and your logs.
5. Now clear the token and Send again → **401**. That's the difference the token
   makes, in one click.

**Step 6 — why the imported collection is nicer.** It ships with:

- `{{base_url}}` = `http://localhost:8000`, so switching servers is one edit
- `{{token}}`, and collection-level auth set to **Bearer `{{token}}`** — every
  request inherits it
- a script on the login request that saves the token automatically:
  ```js
  pm.collectionVariables.set("token", pm.response.json().token);
  ```

So the real workflow is: **run "1. Login" once → everything else just works.**

**Step 7 — what to click.** The collection is numbered in the order to run:

| Request | Expect |
|---|---|
| 1. Login | 200 + token (auto-saved) |
| 2. Who am I | 200, confirms roles |
| 3. List time logs | 200 + `data` and `meta` |
| 4. Show one | 200 |
| 5. Create | **201**, saves the new id into `{{log_id}}` |
| 6. Update | 200 |
| 7. Approve | 200 — run it twice, the second is **422** |
| 8. Delete | **204**, empty body |
| 9. Logout | 204; every request after this 401s until you log in again |
| Failure cases → 401 no token | **401** |
| Failure cases → 403 employee delete | **403** (logs in as the employee for you) |
| Failure cases → 422 × 4 | **422** with an `errors` object |

**Gotchas that would otherwise cost you an hour:**

- **`Accept: application/json` is not optional.** Without it Laravel may reply with
  an HTML redirect and you'll debug the wrong thing.
- **204 shows an empty body.** That's success. Read the status code.
- **On POST/PUT the body must be `raw` + `JSON`**, not `form-data` — otherwise you
  get confusing 422s about missing fields.
- **Pick `localhost` or `127.0.0.1` and stay consistent.** Sanctum treats them as
  different hosts.

## 10. Tests

[`tests/Feature/TimeLogApiTest.php`](tests/Feature/TimeLogApiTest.php) — **28
tests, all passing**:

```bash
php artisan test --filter=TimeLogApiTest
```

Covering: unauthenticated → 401; a garbage token → 401; login returns a usable
token; bad credentials → 422; a disabled account refused a token; a token whose
user was disabled *afterwards* → 403; logout revokes only the calling token;
admin → 403; an employee sees only its own rows and cannot write; a company_admin
cannot touch another company's log; the list never leaks across companies; the
documented response shape; month and status filters; all five verbs; 404;
`time_out` before `time_in`; duplicate date; foreign employee; missing reject
reason; a log cannot change employee; approve stamps the approver and queues the
Task 8 notification; a no-op update doesn't re-notify.

**Whole suite: 81 of 84 passing.** The three failures are pre-existing and
unrelated — verified by stashing all Task 9 work and re-running:

| Test | Failure | Why it's not ours |
|---|---|---|
| `AttendanceLogTest::test_admin_cannot_access_attendance_logs` | expects 403, gets 302 | An admin visiting `/attendance-logs` in a **browser** is redirected to `/my-account` by design — that's the `respond()` hook in `bootstrap/app.php`. The test asserts the API-style answer. One-line fix: use `getJson()` instead of `get()`, or assert the redirect. Left alone — changing a test is your call. |
| `DashboardTest::test_the_date_range_filters…` | `employees.middle_name` NOT NULL | The test creates an Employee without `middle_name`, which the migration requires. |
| `RedisCacheQueueTest::test_approving_a_log_queues…` | same | same |

## 11. Four things that bit us, and what they teach

**1. The route-name collision.** `->name('login')` in `routes/api.php` silently
overwrote the web route of the same name, so `redirectGuestsTo(route('login'))`
began redirecting browsers to `POST /api/login`. Four unrelated tests went red at
once. Fixed with a `Route::name('api.')` group. *Route names are one global
namespace across every route file.*

**2. `Rule::unique` on a date column was never matching under SQLite.** Eloquent
writes a date-cast attribute as `2026-08-13 00:00:00`. MySQL's `DATE` column
drops the time on the way in, so the comparison happened to work in production —
SQLite (what the tests use) keeps it, so the rule silently passed everything.
Replaced with an explicit closure using `whereDate()`, which normalises on both
engines. *A rule that only works on one database engine is a rule you can't test.*

**3. The local database had drifted from the seeder.** `admin@test.com` held
`attendance-logs.view` even though `RolesAndPermissionsSeeder` doesn't grant it —
left over from an older seed run. So the documented "admin gets 403" didn't
reproduce locally, on the API *or* the web UI. Fixed by re-running the seeder
(idempotent — `syncPermissions` re-syncs to the declared source of truth):

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan cache:clear      # Laratrust caches permissions
```

*If permissions behave oddly, re-seed before you debug the code.*

**4. `EnsureAccountIsActive` would have crashed on token requests.** It called
`$request->session()->invalidate()`, and a Bearer-token request has no session at
all. Now guarded with `$request->hasSession()`. *Middleware written for browsers
needs a second look before it sits in front of an API.*

## 12. Verified over real HTTP

Not just PHPUnit — everything below was run against `php artisan serve` on the
real MySQL database:

| Check | Result |
|---|---|
| `POST /api/login` | 200 + token |
| GET / POST / GET one / PUT / DELETE | 200 / 201 / 200 / 200 / 204 |
| `PUT …/approve` | 200, then **422** on the second attempt |
| No token | **401** |
| `admin` token | **403** |
| `employee` token: list vs delete | **200** vs **403** |
| company_admin reading another company's log | **403** |
| `time_out` before `time_in`, foreign employee | **422** with `errors` |
| Disabled account logging in | **422** |
| **Cookie mode**: `GET /api/time-logs` with only the session cookie, no `Authorization` header | **200** — Sanctum's stateful mode confirmed working |
| **CSRF**: `PUT …/approve` by cookie **without** `X-XSRF-TOKEN` | **419** |
| the same request **with** `X-XSRF-TOKEN` | **200** — which is what `withXSRFToken: true` automates |

`npm run build` compiles clean, and `/attendance-logs` renders with the new
bundle.

## 13. Every file touched

| File | Change |
|---|---|
| `composer.json` / `.lock` | + `laravel/sanctum` v4.3.3 |
| `config/sanctum.php` | new (published) |
| `database/migrations/…_create_personal_access_tokens_table.php` | new (published) |
| `.env`, `.env.example` | + `SANCTUM_STATEFUL_DOMAINS` |
| `app/Models/User.php` | + `HasApiTokens` |
| `bootstrap/app.php` | + api route file, + `statefulApi()` |
| `app/Http/Middleware/EnsureAccountIsActive.php` | session-safe for token clients |
| `routes/api.php` | **new** — 9 routes, all named `api.*` |
| `app/Http/Controllers/Api/AuthController.php` | **new** |
| `app/Http/Controllers/Api/TimeLogController.php` | **new** |
| `app/Http/Requests/TimeLogRequest.php` | **new** (abstract, shared) |
| `app/Http/Requests/StoreTimeLogRequest.php` | **new** |
| `app/Http/Requests/UpdateTimeLogRequest.php` | **new** |
| `app/Http/Resources/TimeLogResource.php` | **new** |
| `app/Http/Controllers/Concerns/NotifiesTimeLogDecision.php` | **new** |
| `app/Http/Controllers/AttendanceLogController.php` | refactored onto the shared pieces; behaviour unchanged |
| `package.json` | + `axios` |
| `vite.config.js` | + `@` alias |
| `resources/js/lib/api.js` | **new** |
| `resources/js/Composables/useApi.js` | **new** |
| `resources/js/Pages/AttendanceLogs/AdminIndex.vue` | Axios month / approve / reject / delete |
| `resources/js/Pages/AttendanceLogs/EmployeeIndex.vue` | Axios month navigation |
| `tests/Feature/TimeLogApiTest.php` | **new** — 28 tests |
| `docs/postman/mini-workforce-desk.postman_collection.json` | **new** |
| `README.md` | + the API section with curl samples |

## 14. Task checklist

| Requirement | Where |
|---|---|
| `routes/api.php` with GET/POST/PUT/DELETE time-logs (JSON) | §5 |
| Protected with Sanctum | §3, §4 — `auth:sanctum` + `active` + `permission:` |
| Vue calls them with Axios, not only Inertia posts | §8 — month, approve, reject, delete |
| Loading state | `useApi().loading` → per-row spinners, disabled buttons |
| Validation errors | `useApi().errors` → under the Reject textarea |
| 401 / 403 handled | interceptor → `/login`; `message` banner |
| Auth headers attached correctly | §7 — `withCredentials` + `withXSRFToken` + `X-Requested-With` |
| Tested in Postman | §9 + the collection |
| Tested from the Vue UI | §8 |
| Documented in README | [README.md](README.md#api-task-9) |
