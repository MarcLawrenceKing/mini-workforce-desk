<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        return Inertia::render('Employees/Index', [
            'employees' => Employee::query()
                ->visibleTo($viewer)
                ->with(['company:id,name', 'user:id,email'])
                ->orderBy('last_name')
                ->get(),
            'can' => [
                'create' => $viewer->isAbleTo('employees.create'),
                'edit' => $viewer->isAbleTo('employees.edit'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $viewer = $request->user();

        $validated = $request->validate([
            'employee_no' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_no')->where('company_id', $viewer->company_id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],   // NOT NULL in the migration
            'last_name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:employees,user_id'],
        ]);

        Employee::create([
            ...$validated,
            'company_id' => $viewer->company_id,
        ]);

        return back()->with('success', 'Employee created.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->hasRole('admin') || $viewer->company_id === $employee->company_id,
            403,
        );

        $employee->update($request->validate([
            'employee_no' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_no')
                    ->where('company_id', $employee->company_id)
                    ->ignore($employee->id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ]));

        return back()->with('success', 'Employee updated.');
    }
}
