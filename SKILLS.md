# SKILLS.md

Section A skills checklist for **Mini Workforce Desk**.

| # | Skill | Done | Where |
| - | ----- | ---- | ----- |
| 1 | Git repository and commit history | Yes | `.git` |
| 2 | Local environment (PHP, Composer, Node, MySQL, Redis) | Yes | [SETUP.md](SETUP.md) |
| 3 | Laravel install and configuration | Yes | `config/`, `.env.example` |
| 4 | Migrations and database schema | Yes | `database/migrations/` |
| 5 | Eloquent models and relationships | Yes | `app/Models/` |
| 6 | Seeders and demo data | Yes | `database/seeders/` |
| 7 | Authentication (login, register, logout) | Yes | `app/Http/Controllers/Auth/` |
| 8 | Laratrust roles and permissions | Yes | `database/seeders/RolesAndPermissionsSeeder.php` |
| 9 | Route middleware and access control | Yes | `routes/web.php`, `app/Http/Middleware/` |
| 10 | Policies and authorization | Yes | `app/Policies/` |
| 11 | Inertia + Vue 3 pages | Yes | `resources/js/Pages/` |
| 12 | PrimeVue components and CRUD | Yes | `resources/js/Components/` |
| 13 | Tailwind CSS and responsive layout | Yes | `resources/css/app.css` |
| 14 | Form requests and validation | Yes | `app/Http/Requests/` |
| 15 | Approval workflow | Yes | `app/Http/Controllers/AttendanceLogController.php` |
| 16 | Soft deletes and restore | Yes | `app/Http/Controllers/EmployeeController.php` |
| 17 | Redis cache and invalidation | Yes | `app/Observers/DashboardCacheObserver.php` |
| 18 | Redis queue and worker jobs | Yes | `app/Jobs/SendTimeLogApprovedNotification.php` |
| 19 | Artisan console command | Yes | `app/Console/Commands/FlagMissingTimeLogs.php` |
| 20 | REST API with Sanctum | Yes | `routes/api.php`, `app/Http/Controllers/Api/` |
| 21 | API resources | Yes | `app/Http/Resources/TimeLogResource.php` |
| 22 | Axios API calls from Vue | Yes | `resources/js/lib/api.js` |
| 23 | Socket.IO realtime updates | Yes | `socket-server/server.js` |
| 24 | File upload and storage | Yes | `app/Http/Controllers/EmployeeController.php` |
| 25 | CSV export | Yes | `app/Http/Controllers/TimeLogExportController.php` |
| 26 | Activity logging | Yes | `app/Services/ActivityLogger.php` |
| 27 | Security basics (CSRF, hashing, validation, 403 handling) | Yes | `bootstrap/app.php` |
| 28 | Automated tests | Yes | `tests/Feature/` |
| 29 | Documentation (README, setup, task guides) | Yes | [README.md](README.md) |
