<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
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

        return Inertia::render('AdminDashboard', [
            'kpis' => [
                'employees' => Employee::query()->where($withinCreatedRange)->count(),
                'companies' => Company::query()->where($withinCreatedRange)->count(),
                'users' => User::query()->count(),
            ],
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }
}
