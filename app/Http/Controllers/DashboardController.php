<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Safety net only. DashboardCacheObserver clears these keys the moment the
     * underlying rows change, so the TTL just stops a forgotten key living forever.
     */
    private const CACHE_TTL_SECONDS = 600;

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

        // One cache entry per filter combination — /dashboard and
        // /dashboard?from=2026-01-01 are different questions with different answers.
        $key = sprintf(
            'dashboard:kpis:%s:%s',
            $filters['from'] ?? 'any',
            $filters['to'] ?? 'any',
        );

        // remember() = "give me this key; if it's missing, run the closure,
        // store what it returns, and hand it back".
        $kpis = Cache::tags(['dashboard'])->remember(
            $key,
            self::CACHE_TTL_SECONDS,
            fn() => $this->countKpis($filters),
        );

        return Inertia::render('AdminDashboard', [
            'kpis' => $kpis,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    /**
     * The three counts behind the tiles. Only ever runs on a cache miss.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    private function countKpis(array $filters): array
    {
        $withinCreatedRange = function (Builder $query) use ($filters): void {
            $query
                ->when(
                    $filters['from'] ?? null,
                    fn(Builder $query, string $from) => $query->whereDate('created_at', '>=', $from),
                )
                ->when(
                    $filters['to'] ?? null,
                    fn(Builder $query, string $to) => $query->whereDate('created_at', '<=', $to),
                );
        };

        return [
            'employees' => Employee::query()->where($withinCreatedRange)->count(),
            'companies' => Company::query()->where($withinCreatedRange)->count(),
            'users' => User::query()->count(),
        ];
    }
}
