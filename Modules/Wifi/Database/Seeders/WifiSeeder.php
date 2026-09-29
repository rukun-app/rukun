<?php

namespace Modules\Wifi\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WifiSeeder extends Seeder
{
    public const PERMISSIONS = ['wifi.view', 'wifi.manage', 'wifi.settle', 'wifi.deliver'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['super-admin', 'bendahara-rw', 'bendahara-rt'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(self::PERMISSIONS);
        }
        Role::findOrCreate('vendor-wifi', 'web')->givePermissionTo('wifi.deliver');
        foreach (['ketua-rw', 'ketua-rt', 'vendor-wifi'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo('wifi.view');
        }
    }
}
