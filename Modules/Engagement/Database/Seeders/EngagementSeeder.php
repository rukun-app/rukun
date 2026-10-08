<?php

namespace Modules\Engagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EngagementSeeder extends Seeder
{
    public const PERMISSIONS = ['patrol.manage', 'patrol.policy.manage', 'activities.manage', 'activities.fees.manage'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['super-admin', 'ketua-rw', 'ketua-rt', 'bendahara-rw', 'bendahara-rt'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo(self::PERMISSIONS);
        }
        foreach (['sekretaris-rw', 'sekretaris-rt'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo(['patrol.manage', 'activities.manage']);
        }
        Role::findOrCreate('koordinator-ronda', 'web')->givePermissionTo('patrol.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
