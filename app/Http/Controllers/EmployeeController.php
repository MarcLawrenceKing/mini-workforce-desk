<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        // Soft-deleted rows stay out of the list until the toggle asks for them.
        $withTrashed = $request->boolean('trashed');

        return Inertia::render('Employees/Index', [
            'employees' => Employee::query()
                ->visibleTo($viewer)
                ->when($withTrashed, fn ($query) => $query->withTrashed())
                ->with(['company:id,name', 'user:id,name,email'])
                ->orderBy('last_name')
                ->get()
                ->map(fn (Employee $employee) => [
                    ...$employee->only([
                        'id', 'employee_no', 'full_name', 'company_id', 'user_id',
                        'first_name', 'middle_name', 'last_name', 'photo_url',
                    ]),
                    'company' => $employee->company?->only(['id', 'name']),
                    'user' => $employee->user?->only(['id', 'name', 'email']),
                    'deleted_at' => $employee->deleted_at?->toDateTimeString(),
                ]),
            'trashed' => $withTrashed,

            // An admin has no company_id of its own, so it picks the target company.
            'companies' => Company::visibleTo($viewer)->orderBy('name')->get(['id', 'name']),

            // Only accounts that aren't already tied to an employee record are
            // offered; the row's own account is merged back in by the dialog.
            'linkableUsers' => User::query()
                ->visibleTo($viewer)
                ->whereDoesntHave('employee')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'company_id']),

            'can' => [
                'create' => $viewer->isAbleTo('employees.create'),
                'edit' => $viewer->isAbleTo('employees.edit'),
                'delete' => $viewer->isAbleTo('employees.delete'),
                'assignAnyCompany' => $viewer->hasRole('admin'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $isAdmin = $viewer->hasRole('admin');

        // A company_admin can only ever create inside its own company; the value
        // an admin picked is validated below before it reaches the query.
        $companyId = $isAdmin ? $request->integer('company_id') : $viewer->company_id;

        $validated = $request->validate([
            // Required for an admin, who has no company of its own to fall back on.
            'company_id' => [Rule::requiredIf($isAdmin), 'nullable', 'integer', 'exists:companies,id'],
            'employee_no' => [
                'required', 'string', 'max:255',
                // Not ->withoutTrashed(): the unique index spans soft-deleted rows,
                // so a reused number has to fail here rather than at the database.
                Rule::unique('employees', 'employee_no')->where('company_id', $companyId),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],   // NOT NULL in the migration
            'last_name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:employees,user_id'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $photoPath = $request->file('photo')?->store('employee-photos', 'public');

        Employee::create([
            ...Arr::except($validated, ['photo']),
            'company_id' => $companyId,
            'photo_url' => $photoPath,
        ]);

        return back()->with('success', 'Employee created.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->assertInScope($request, $employee);

        $validated = $request->validate([
            'employee_no' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_no')
                    ->where('company_id', $employee->company_id)
                    ->ignore($employee->id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'user_id' => [
                'nullable', 'integer', 'exists:users,id',
                Rule::unique('employees', 'user_id')->ignore($employee->id),
            ],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $oldPhotoPath = $employee->getRawOriginal('photo_url');
        $newPhotoPath = $request->file('photo')?->store('employee-photos', 'public');

        $employee->update([
            ...Arr::except($validated, ['photo']),
            ...($newPhotoPath ? ['photo_url' => $newPhotoPath] : []),
        ]);

        if ($newPhotoPath && $oldPhotoPath) {
            Storage::disk('public')->delete($oldPhotoPath);
        }

        return back()->with('success', 'Employee updated.');
    }

    /** Soft delete — the row keeps its employee_no reservation and can be restored. */
    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $this->assertInScope($request, $employee);

        $employee->delete();

        return back()->with('success', 'Employee deleted.');
    }

    public function restore(Request $request, Employee $employee): RedirectResponse
    {
        $this->assertInScope($request, $employee);

        $employee->restore();

        return back()->with('success', 'Employee restored.');
    }

    /** 403 when a company_admin targets an employee outside its own company. */
    private function assertInScope(Request $request, Employee $employee): void
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->hasRole('admin') || $viewer->company_id === $employee->company_id,
            403,
        );
    }
}
