<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BillingSeeder extends Seeder
{
    public const PERMISSIONS = ['payments.gateway.create', 'payments.gateway.reconcile', 'billing.manage', 'invoices.view', 'invoices.manage', 'receipts.view', 'receipts.create', 'receipts.reverse', 'payments.manual.submit', 'payments.manual.view', 'payments.manual.approve', 'payments.manual.reject', 'ledger.view', 'ledger.adjust', 'expenses.manage', 'expenses.approve', 'expenses.post', 'periods.close', 'reports.view'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['super-admin', 'bendahara-rw', 'bendahara-rt'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(self::PERMISSIONS);
        }
        foreach (['ketua-rw', 'ketua-rt'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(['invoices.view', 'receipts.view', 'payments.manual.view', 'payments.manual.approve', 'payments.manual.reject', 'expenses.approve', 'reports.view', 'ledger.view', 'periods.close']);
        }
    }
}
