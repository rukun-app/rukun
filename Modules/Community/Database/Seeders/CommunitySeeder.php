<?php

namespace Modules\Community\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CommunitySeeder extends Seeder
{
    public const PERMISSIONS = ['areas.view', 'areas.manage', 'accounts.provision', 'users.recover-account'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('super-admin', 'web')->givePermissionTo(self::PERMISSIONS);
        foreach (['ketua-rw', 'ketua-rt', 'sekretaris-rw', 'sekretaris-rt'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo(self::PERMISSIONS);
        }
        foreach (['bendahara-rw', 'bendahara-rt', 'koordinator-ronda', 'vendor-wifi', 'warga'] as $name) {
            Role::findOrCreate($name, 'web');
        }
    }
}
