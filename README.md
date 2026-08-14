# Mini Workforce Desk

A small workforce management app: companies, employees, attendance logs, and approvals.

**Stack:** Laravel 13 · Inertia + Vue 3 · PrimeVue · Tailwind 4 · MySQL · Redis · Socket.IO · Sanctum · Laratrust

See [ARCHITECTURE.md](ARCHITECTURE.md) for how the pieces fit together, and [SKILLS.md](SKILLS.md) for the skills checklist.

---

## Requirements

| Tool     | Version |
| -------- | ------- |
| PHP      | 8.3+ (with `redis`, `pdo_mysql`) |
| Composer | 2.8+    |
| Node.js  | 20+     |
| MySQL    | 8.0+    |
| Redis    | 7.0+    |

## Install

```bash
git clone <repo-url>
cd mini-workforce-desk

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Edit `.env` — set your MySQL credentials and a value for `REALTIME_SECRET`:

```dotenv
DB_DATABASE=mini_workforce_desk
DB_USERNAME=root
DB_PASSWORD=

REALTIME_SECRET=any-random-string
```

Create the database, then run:

```bash
php artisan migrate --seed
php artisan storage:link
npm run build
```

## Services to run

Each in its own terminal.

| # | Service       | Command                     | Notes                          |
| - | ------------- | --------------------------- | ------------------------------ |
| 1 | MySQL         | (system service)            | Must be running before migrate |
| 2 | Redis         | `redis-server`              | Cache, queue, and sessions     |
| 3 | Laravel       | `php artisan serve`         | http://127.0.0.1:8000          |
| 4 | Vite          | `npm run dev`               | Frontend assets (dev only)     |
| 5 | Queue worker  | `php artisan queue:work --queue=notifications` | Approval notifications |
| 6 | Socket server | `npm run socket`            | http://127.0.0.1:3001          |

Open http://127.0.0.1:8000 and log in.

## Demo accounts

Seeded by `php artisan db:seed`. Password for all accounts: **`password`**

| Email                    | Role            | Notes                                |
| ------------------------ | --------------- | ------------------------------------ |
| admin@test.com           | `admin`         | Full system access, no company        |
| admin@company1.com       | `company_admin` | Manages Company One                   |
| employee@company1.com    | `employee`      | Own attendance logs only              |
| admin@company2.com       | `company_admin` | Manages Company Two                   |
| employee@company2.com    | `employee`      | Own attendance logs only              |
| disabled@company1.com    | `employee`      | Disabled — blocked at login           |

> The seeder skips `admin@test.com` if an admin already exists. `/register` is a one-time
> admin bootstrap and closes itself once that admin is created.

## Roles

| Role            | Can do                                                          |
| --------------- | --------------------------------------------------------------- |
| `admin`         | Users, companies, employees                                      |
| `company_admin` | Own company's users, employees, attendance logs, approvals, CSV export |
| `employee`      | Own account and own attendance logs                              |

Anything outside a role returns **403** (JSON for API, redirect with a flash message for pages).

## API

Session-authenticated for the Vue app, bearer-token for external clients.

```bash
# Get a token
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Accept: application/json" \
  -d "email=employee@company1.com&password=password&device_name=postman"

# Use it
curl http://127.0.0.1:8000/api/time-logs \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <token>"
```

Endpoints: `/api/login` · `/api/logout` · `/api/user` · `/api/time-logs` (CRUD) ·
`/api/time-logs/{id}/approve` · `/api/time-logs/export`

A Postman collection is in [docs/postman/](docs/postman/).

## Artisan commands

```bash
php artisan timelogs:flag-missing   # Flag employees with no attendance log today
```

## Tests

```bash
php artisan test
```

## Other docs

- [SETUP.md](SETUP.md) — local environment setup notes
- [CACHE_AND_QUEUE.md](CACHE_AND_QUEUE.md) — Redis cache and queue details
- `TASK-*-GUIDE.md` — per-task implementation notes
