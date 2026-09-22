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
        $permissions = ['users.view', 'users.create', 'users.suspend', 'users.assign-roles', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'settings.view', 'settings.update', 'audit.view'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('super-admin', 'web')->syncPermissions($permissions);
        Role::findOrCreate('admin', 'web')->syncPermissions(['users.view', 'users.create', 'users.suspend', 'users.assign-roles', 'roles.view', 'settings.view', 'settings.update', 'audit.view']);
        Role::findOrCreate('user', 'web');
    }
}
