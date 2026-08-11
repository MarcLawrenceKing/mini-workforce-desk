<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Module.action permission names, kept flat so the Vue side can do
     * a plain `permissions.includes('users.view')` check.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'users.view' => 'View users',
        'users.create' => 'Create users',
        'users.edit' => 'Edit users',

        'companies.view' => 'View companies',
        'companies.create' => 'Create companies',
        'companies.edit' => 'Edit companies',

        'employees.view' => 'View employees',
        'employees.create' => 'Create employees',
        'employees.edit' => 'Edit employees',
        // Covers the soft delete and the restore that undoes it.
        'employees.delete' => 'Delete employees',

        'attendance-logs.view' => 'View attendance logs',
        'requests.view' => 'View requests',
    ];

    /**
     * @var array<string, array{display_name: string, permissions: list<string>}>
     */
    private const ROLES = [
        'admin' => [
            'display_name' => 'Administrator',
            'permissions' => [
                'users.view', 'users.create', 'users.edit',
                'companies.view', 'companies.create', 'companies.edit',
                'employees.view', 'employees.create', 'employees.edit', 'employees.delete',
            ],
        ],
        'company_admin' => [
            'display_name' => 'Company Administrator',
            // No companies.create: a company_admin manages its own company, never new ones.
            'permissions' => [
                'users.view', 'users.create', 'users.edit',
                'companies.view', 'companies.edit',
                'employees.view', 'employees.create', 'employees.edit', 'employees.delete',
                'requests.view',
            ],
        ],
        'employee' => [
            'display_name' => 'Employee',
            // Deliberately no employees.view: that permission gates the company-wide
            // /employees list. An employee reaches their own record via /my-account.
            'permissions' => [
                'attendance-logs.view',
                'requests.view',
            ],
        ],
    ];

    public function run(): void
    {
        $roleClass = config('laratrust.models.role');
        $permissionClass = config('laratrust.models.permission');

        $permissions = [];

        foreach (self::PERMISSIONS as $name => $displayName) {
            $permissions[$name] = $permissionClass::updateOrCreate(
                ['name' => $name],
                ['display_name' => $displayName],
            );
        }

        foreach (self::ROLES as $name => $definition) {
            $role = $roleClass::updateOrCreate(
                ['name' => $name],
                ['display_name' => $definition['display_name']],
            );

            $role->syncPermissions(
                array_map(fn (string $permission) => $permissions[$permission], $definition['permissions']),
            );
        }
    }
}
