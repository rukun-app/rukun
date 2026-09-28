<?php

namespace Modules\Community\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CommunitySeeder extends Seeder
{
    public const PERMISSIONS = ['areas.view', 'areas.manage', 'accounts.provision', 'users.recover-account', 'households.view', 'households.manage', 'residents.view', 'residents.manage', 'residents.view-sensitive', 'households.manage-accounts', 'population.transfer', 'vendors.view', 'vendors.manage'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('super-admin', 'web')->givePermissionTo(self::PERMISSIONS);
        foreach (['ketua-rw', 'ketua-rt', 'sekretaris-rw', 'sekretaris-rt'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo(array_diff(self::PERMISSIONS, ['vendors.manage', 'vendors.view', 'households.manage-accounts']));
        }
        Role::findOrCreate('warga', 'web')->givePermissionTo(['households.view', 'residents.view']);
        Role::findOrCreate('household-account-manager', 'web')->givePermissionTo(['households.view', 'residents.view', 'households.manage-accounts']);
        Role::findOrCreate('vendor-wifi', 'web')->givePermissionTo('vendors.view');
        foreach (['bendahara-rw', 'bendahara-rt', 'koordinator-ronda', 'vendor-wifi', 'warga'] as $name) {
            Role::findOrCreate($name, 'web');
        }
    }
}
