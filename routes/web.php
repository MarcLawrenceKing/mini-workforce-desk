<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn() => Inertia::render('Welcome', [
    'title' => 'Mini Workforce Desk',
    'description' => 'A small Laravel + Inertia + Vue workspace for tracking staff, roles, and availability.',
]));

Route::get('/task2', fn() => Inertia::render('Dashboard', [
    'staff' => [
        ['id' => 1, 'name' => 'Ada Lovelace', 'role' => 'Engineer', 'status' => 'Active'],
        ['id' => 2, 'name' => 'Grace Hopper', 'role' => 'Manager',  'status' => 'On leave'],
    ],
]));
