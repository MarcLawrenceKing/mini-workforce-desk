<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TimeLogController;
use App\Http\Controllers\TimeLogExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Task 9 — JSON API
|--------------------------------------------------------------------------
|
| Everything here is automatically prefixed with /api. Two kinds of client
| authenticate against it, and `auth:sanctum` accepts both:
|
|   • the Vue UI — same browser, already logged in at /login, so its session
|     cookie is enough. No token is ever handed to JavaScript.
|   • Postman / curl / a future mobile app — no session, so they trade
|     credentials for a bearer token at POST /api/login below.
|
| Failure modes, all returned as JSON (see shouldRenderJsonWhen in
| bootstrap/app.php):
|   401 not authenticated · 403 authenticated but not allowed · 422 invalid data
|
*/

// Every name is prefixed `api.` — without it `->name('login')` here would
// overwrite the web route of the same name, and redirectGuestsTo() would start
// sending browsers to POST /api/login.
Route::name('api.')->group(function () {
    // The only public endpoint: swap credentials for a token.
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/user', [AuthController::class, 'me'])->name('user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Same gate as the web UI: company_admin and employee hold
        // attendance-logs.view; admin does not, and gets a 403 here too.
        Route::middleware('permission:attendance-logs.view')->group(function () {
            Route::get('/time-logs/export', TimeLogExportController::class)
                ->middleware('role:company_admin')
                ->name('time-logs.export');

            Route::apiResource('time-logs', TimeLogController::class)
                ->parameters(['time-logs' => 'timeLog']);

            Route::put('/time-logs/{timeLog}/approve', [TimeLogController::class, 'approve'])
                ->whereNumber('timeLog')
                ->name('time-logs.approve');
        });
    });
});
