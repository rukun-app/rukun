<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['users.view', 'users.create', 'users.suspend', 'users.assign-roles', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'settings.view', 'settings.update', 'audit.view', 'files.view-any', 'files.download-any', 'files.update-any', 'files.delete-any', 'payments.create', 'payments.view-any', 'data-transfers.create', 'data-transfers.view-any', 'data-transfers.cancel-any'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('super-admin', 'web')->syncPermissions($permissions);
        Role::findOrCreate('admin', 'web')->syncPermissions(['users.view', 'users.create', 'users.suspend', 'users.assign-roles', 'roles.view', 'settings.view', 'settings.update', 'audit.view', 'files.view-any', 'files.download-any', 'files.update-any', 'files.delete-any', 'payments.create', 'payments.view-any', 'data-transfers.create', 'data-transfers.view-any', 'data-transfers.cancel-any']);
        Role::findOrCreate('user', 'web');
    }
}
