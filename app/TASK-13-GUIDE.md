## Task 13: UI polish and security basics

### Consistent responsive layout

Shared layout rules live in `resources/css/app.css`. Pages use the `.page` container, `.stack` vertical spacing, and reusable furniture such as `.page-header`, `.table-toolbar`, and `.field`. The global gutters increase at `1024px`; the navigation switches between a mobile drawer and desktop sidebar at `768px`; table search and responsive grids switch at `640px`.

Example:

```vue
<section class="page">
    <header class="page-header">
        <h1 class="page-title">Employees</h1>
    </header>
    <Card>...</Card>
</section>
```

### PrimeVue feedback and theme

`resources/js/app.js` applies PrimeVue's Aura preset, the shared light/dark selector, `ToastService`, and `ConfirmationService`. `FlashMessages.vue` turns Laravel success/error flash data into PrimeVue toasts. Delete actions call `useConfirm()` and the layouts render one shared `<ConfirmDialog />`.

### CSRF protection

Inertia's `useForm()` and `router` requests use Laravel's normal web middleware and CSRF token automatically. The shared Axios client in `resources/js/lib/api.js` uses `withCredentials: true` and `withXSRFToken: true`, so Axios reads Laravel's `XSRF-TOKEN` cookie and returns it as the `X-XSRF-TOKEN` header. A `419` response reloads the page to obtain a fresh token. Non-browser API clients authenticate with Sanctum bearer tokens instead of CSRF.

### Mass assignment protection

Mass assignment means passing an array to methods such as `Model::create($request->all())` or `$model->update($data)`. Without a whitelist, a malicious request could set fields the UI never intended to expose, such as `is_disabled`, `company_id`, or an approval field. Every application model therefore declares an explicit `#[Fillable([...])]` whitelist. Controllers still validate requests and pass only validated attributes; `$guarded = []` is not used.

### Environment secrets

`.env` is ignored by Git and is not tracked. Commit only `.env.example`, using empty values or placeholders for machine-specific credentials and secrets. Verify at any time with:

```bash
git ls-files .env .env.example
```

The output should contain only `.env.example`.

### Activity logging

The `activity_logs` table stores `user_id`, `action`, `subject`, and `created_at`. `App\\Services\\ActivityLogger` is injected into both web and API authentication controllers, recording successful login and logout actions while keeping the implementation reusable:

```php
$activityLogger->log($user, 'login', 'web session');
```

Run `php artisan migrate` after pulling this change.
