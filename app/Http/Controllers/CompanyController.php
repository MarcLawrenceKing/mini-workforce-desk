<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Company::class);

        $viewer = $request->user();

        return Inertia::render('Companies/Index', [
            'companies' => Company::query()
                ->visibleTo($viewer)                  // company_admin only ever sees its own
                ->withCount(['users', 'employees'])
                ->orderBy('name')
                ->get(),
            'can' => [
                'create' => $viewer->isAbleTo('companies.create'),
                'edit' => $viewer->isAbleTo('companies.edit'),
                'delete' => $viewer->hasRole('admin'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Company::class);

        Company::create($request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Company created.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        // 403 when a company_admin targets a company that isn't theirs.
        $this->authorize('update', $company);

        $company->update($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($company->id)],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Company updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorize('delete', $company);

        // users.company_id and employees.company_id are RESTRICT foreign keys,
        // so a non-empty company would fail at the database instead of here.
        // (Employees count even when soft-deleted — the row is still there.)
        if ($company->users()->exists() || $company->employees()->withTrashed()->exists()) {
            return back()->with(
                'error',
                'That company still has users or employees. Move or remove them first.',
            );
        }

        $company->delete();

        return back()->with('success', 'Company deleted.');
    }
}
