<?php

namespace Modules\Civic\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CivicSeeder extends Seeder
{
    public const PERMISSIONS = ['announcements.view', 'announcements.manage', 'reports.manage', 'letters.manage'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['super-admin', 'ketua-rw', 'ketua-rt', 'sekretaris-rw', 'sekretaris-rt'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(self::PERMISSIONS);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
