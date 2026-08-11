<?php

use App\Http\Controllers\AttendanceLogController;
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

Route::get('/', fn() => Inertia::render('Welcome', [
    'title' => 'Mini Workforce Desk',
    'description' => 'A small Laravel + Inertia + Vue workspace for tracking staff, roles, and availability.',
]))->name('home');

// Task 2 demo page — intentionally left public and unauthenticated.
Route::get('/task2', fn() => Inertia::render('Dashboard', [
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

    // Landing page for every role. No permission gate on purpose: everyone may
    // see and edit their own account.
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

        // Soft delete, and the restore that undoes it. `withTrashed` on the
        // binding is what lets a deleted row still resolve for the restore.
        Route::middleware('permission:employees.delete')->group(function () {
            Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('destroy');

            Route::put('/{employee}/restore', [EmployeeController::class, 'restore'])
                ->withTrashed()
                ->name('restore');
        });
    });

    // --- Attendance logs -------------------------------------------------
    Route::prefix('attendance-logs')->name('attendance-logs.')
        ->middleware('permission:attendance-logs.view')->group(function () {
            Route::get('/', [AttendanceLogController::class, 'index'])->name('index');
            Route::post('/', [AttendanceLogController::class, 'store'])->name('store');
            Route::post('/check-in', [AttendanceLogController::class, 'checkIn'])->name('check-in');
            Route::put('/check-out', [AttendanceLogController::class, 'checkOut'])->name('check-out');
            Route::put('/{attendanceLog}', [AttendanceLogController::class, 'update'])
                ->whereNumber('attendanceLog')
                ->name('update');
            Route::put('/{attendanceLog}/approve', [AttendanceLogController::class, 'approve'])
                ->whereNumber('attendanceLog')
                ->name('approve');
        });

    // --- Placeholders ----------------------------------------------------

    Route::get('/requests', fn() => Inertia::render('UnderConstruction', [
        'title' => 'Requests',
        'blurb' => 'Leave and overtime approvals land here in a later task.',
    ]))->middleware('permission:requests.view')->name('requests.index');
});
