# SETUP.md — Task 1: Tooling Setup

Local development environment for **mini-workforce-desk** (Laravel skeleton + Blade + Vite + Tailwind).

Host: Windows 11 Pro (10.0.26200), PowerShell 5.1.

---

## Installed versions

| Tool         | Version                         | Where it runs             |
| ------------ | ------------------------------- | ------------------------- |
| PHP          | 8.5.0 (cli, NTS, VC++ 2022 x64) | Windows                   |
| Composer     | 2.10.2                          | Windows                   |
| Node.js      | v24.18.1                        | Windows                   |
| npm          | 11.16.0                         | Windows                   |
| MySQL        | 8.0.46 (Community Server - GPL) | Windows service `MySQL80` |
| Redis        | 7.0.15                          | WSL2 — Ubuntu 24.04.1 LTS |
| WSL          | 2.7.11.0 (kernel 6.18.33.2-2)   | Windows                   |
| Laravel      | ^13.8                           | — (composer constraint)   |
| Vite         | ^8.0.0                          | — (npm constraint)        |
| Tailwind CSS | ^4.0.0                          | — (npm constraint)        |

Required PHP extensions present: `redis` (phpredis), `pdo_mysql`, `mysqli`.

This project is the plain Laravel skeleton — no Vue, Inertia, or TypeScript. The frontend is
Blade (`resources/views/welcome.blade.php`) with Vite bundling `resources/css/app.css` and
`resources/js/app.js`, plus Tailwind 4 via `@tailwindcss/vite`.

---

## Proof commands

Run each from PowerShell. Expected output is shown beneath the command.

### PHP

```powershell
php --version
```

```
PHP 8.5.0 (cli) (built: Nov 21 2025 13:38:22) (NTS Visual C++ 2022 x64)
Zend Engine v4.5.0 ... with Zend OPcache v8.5.0
```

### Composer

```powershell
composer --version
```

```
Composer version 2.10.2 2026-07-01 11:24:45
PHP version 8.5.0 (C:\Users\Sixpent PC\.config\herd-lite\bin\php.exe)
```

### Node.js and npm

```powershell
node --version
npm --version
```

```
v24.18.1
11.16.0
```

### MySQL

```powershell
mysql --version
Get-Service MySQL80 | Select-Object Name, Status
```

```
mysql.exe  Ver 8.0.46 for Win64 on x86_64 (MySQL Community Server - GPL)

Name    Status
----    ------
MySQL80 Running
```

### Redis — the required proof

```powershell
redis-cli ping
```

```
PONG
```

Version check:

```powershell
redis-cli --version
```

```
redis-cli 7.0.15
```

> **Run `redis-cli ping` first each session.** Redis lives in WSL2, and WSL2 shuts its VM down
> when idle — taking Redis with it. Anything connecting straight to `127.0.0.1:6379` (Laravel
> included) then fails with:
>
> ```
> RedisException  No connection could be made because the target machine actively refused it.
> ```
>
> `redis-cli ping` boots WSL and starts the service, so it doubles as the fix. Confirm with
> `wsl --list --running` — "no running distributions" means this is what happened.

### PHP extensions

```powershell
php -m | Select-String -Pattern '^(redis|pdo_mysql|mysqli)$'
```

```
mysqli
pdo_mysql
redis
```

### End-to-end: Laravel talking to both services

The strongest proof — the framework itself reaching MySQL and Redis. Requires
`composer install` to have been run first:

```powershell
php artisan tinker --execute="Illuminate\Support\Facades\Redis::set('probe','ok'); echo 'REDIS='.Illuminate\Support\Facades\Redis::get('probe').PHP_EOL; echo 'MYSQL='.Illuminate\Support\Facades\DB::selectOne('select version() v')->v.PHP_EOL;"
```

```
REDIS=ok
MYSQL=8.0.46
```

---

## Database setup

The Laravel skeleton ships with `DB_CONNECTION=sqlite`. This project uses MySQL instead:
8.0.46 runs as the Windows service `MySQL80` and listens on `127.0.0.1:3306`. The app connects
as `root` over TCP.

Create the schema once:

```powershell
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS mini_workforce_desk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### `.env` configuration

Replace the commented-out sqlite block with:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mini_workforce_desk
DB_USERNAME=root
DB_PASSWORD=<your local MySQL root password>
```

### Running migrations

```powershell
php artisan migrate
```

### Verifying

```powershell
php artisan migrate:status
```

```
 Migration name .............................................. Batch / Status
 0001_01_01_000000_create_users_table ............................. [1] Ran
 0001_01_01_000001_create_cache_table ............................. [1] Ran
 0001_01_01_000002_create_jobs_table .............................. [1] Ran
```

### Resetting

To drop everything and rebuild from scratch:

```powershell
php artisan migrate:fresh
```

---

## Environment configuration

Redis-related `.env` values:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Note that `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION` are all set to `database`, so
Redis is installed and proven but not yet carrying application traffic. Switch them to `redis`
when the app should actually use it. This is also why the `cache`, `sessions`, and `jobs` tables
exist in MySQL.

---

## Fresh-machine bootstrap

```powershell
git clone <repo> mini-workforce-desk
cd mini-workforce-desk
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
# edit .env for MySQL (see Database setup), then:
php artisan migrate
npm run dev      # Vite dev server
php artisan serve
```

`composer.json` also defines shortcuts for this:

- `composer setup` — install, `.env`, key, migrate, `npm install`, `npm run build`
- `composer dev` — runs `php artisan serve`, `queue:listen`, `pail`, and `npm run dev` together
- `composer test` — clears config, then `php artisan test`
