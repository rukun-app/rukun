<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Services\BillingReport;
use Modules\Billing\Services\BillingService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\PopulationService;
use Modules\Wifi\Models\WifiBill;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiPackage;
use Modules\Wifi\Services\WifiService;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->setTime(12, 0));
    Cache::store('redis')->getStore()->setPrefix('wifi-test:'.Str::uuid().':');
    config()->set('broadcasting.default', 'null');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->rt = Area::factory()->rt()->create();
    $this->household = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->vendor = Vendor::query()->create(['name' => 'WiFi Uji', 'status' => 'active']);
    $this->treasurer = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $this->treasurer->id, 'role_id' => Role::findByName('bendahara-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->rt->id]);
    $this->vendorUser = User::factory()->create();
    $this->vendorAssignment = RoleAssignment::factory()->create(['user_id' => $this->vendorUser->id, 'role_id' => Role::findByName('vendor-wifi', 'web')->id, 'scope_type' => 'vendor', 'area_id' => null, 'vendor_id' => $this->vendor->id]);
    $this->billing = app(BillingService::class);
    $type = $this->billing->paymentType($this->treasurer, 'wifi-type-key', ['area_id' => $this->rt->public_id, 'code' => 'WIFI', 'name' => 'WiFi', 'collection_policy' => 'must_settle_in_period', 'fund_classification' => 'pass_through']);
    $this->type = PaymentType::query()->where('public_id', $type['public_id'])->firstOrFail();
    $this->billing->tariff($this->treasurer, 'wifi-tariff-key', $this->type, ['amount' => 150000, 'starts_at' => '2026-01-01']);
    $this->packageData = ['vendor_id' => $this->vendor->public_id, 'payment_type_id' => $this->type->public_id, 'name' => 'WiFi 20 Mbps', 'due_day' => 20, 'settle_day' => 25];
    $this->wifi = app(WifiService::class);
    $package = $this->wifi->package($this->treasurer, 'wifi-package-key', $this->packageData);
    $this->package = WifiPackage::query()->where('public_id', $package['public_id'])->firstOrFail();
    $this->customerData = ['package_id' => $this->package->public_id, 'household_id' => $this->household->public_id, 'starts_on' => '2026-09-05'];
    $customer = $this->wifi->customer($this->treasurer, 'wifi-customer-key', $this->customerData);
    $this->customer = WifiCustomer::query()->where('public_id', $customer['public_id'])->firstOrFail();
    $this->actingAs($this->treasurer);
});

it('issues one tariff-snapshot invoice per subscription month and supports partial pass-through receipts', function () {
    $path = '/api/wifi/customers/'.$this->customer->public_id.'/bill';
    $first = $this->postJson($path, ['period' => '2026-09'], ['Idempotency-Key' => 'wifi-bill-key'])->assertCreated()->json('data.invoice_id');
    $this->postJson($path, ['period' => '2026-09'], ['Idempotency-Key' => 'wifi-bill-key'])->assertCreated()->assertJsonPath('data.invoice_id', $first);
    $this->postJson($path, ['period' => '2026-09'], ['Idempotency-Key' => 'wifi-bill-other-key'])->assertCreated()->assertJsonPath('data.invoice_id', $first);
    $this->postJson($path, ['period' => '2026-10'], ['Idempotency-Key' => 'wifi-bill-key'])->assertConflict();
    $invoice = Invoice::query()->where('public_id', $first)->firstOrFail();
    expect(Invoice::query()->count())->toBe(1)->and(WifiBill::query()->count())->toBe(1)
        ->and($invoice->amount)->toBe(150000)->and($invoice->due_date->toDateString())->toBe('2026-09-20')
        ->and($invoice->settle_by->toDateString())->toBe('2026-09-25')->and($invoice->fund_classification)->toBe('pass_through');
    foreach ([50000, 100000] as $amount) {
        $this->billing->cash($this->treasurer, 'wifi-cash-'.$amount, ['household_id' => $this->household->public_id, 'amount' => $amount, 'paid_on' => '2026-09-20', 'allocations' => [['invoice_id' => $first, 'amount' => $amount]]]);
    }
    expect(app(BillingReport::class)->paid($invoice))->toBe(150000)
        ->and((int) LedgerEntry::query()->where('fund_classification', 'pass_through')->sum('amount'))->toBe(150000)
        ->and(LedgerEntry::query()->where('fund_classification', 'operational')->count())->toBe(0);
    $this->postJson('/api/billing/invoices/generate', ['area_id' => $this->rt->public_id, 'payment_type_id' => $this->type->public_id, 'period' => '2026-09', 'due_date' => '2026-09-20'], ['Idempotency-Key' => 'bypass-wifi-key'])->assertConflict();
});

it('replays writes and rejects missing keys, overlapping subscriptions and inconsistent package settings', function () {
    $this->postJson('/api/wifi/packages', $this->packageData, ['Idempotency-Key' => 'wifi-package-key'])->assertCreated()->assertJsonPath('data.public_id', $this->package->public_id);
    $this->postJson('/api/wifi/packages', [...$this->packageData, 'settle_day' => 19], ['Idempotency-Key' => 'bad-days-key'])->assertUnprocessable();
    $this->postJson('/api/wifi/customers', $this->customerData, ['Idempotency-Key' => 'wifi-customer-key'])->assertCreated()->assertJsonPath('data.public_id', $this->customer->public_id);
    $this->postJson('/api/wifi/customers', $this->customerData)->assertUnprocessable();
    $this->postJson('/api/wifi/customers', $this->customerData, ['Idempotency-Key' => 'duplicate-customer-key'])->assertConflict();
    $wrong = $this->billing->paymentType($this->treasurer, 'wrong-wifi-type', ['area_id' => $this->rt->public_id, 'code' => 'OTHER', 'name' => 'Other', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational']);
    $this->postJson('/api/wifi/packages', [...$this->packageData, 'payment_type_id' => $wrong['public_id']], ['Idempotency-Key' => 'invalid-type-key'])->assertUnprocessable();
});

it('restricts vendors to their customers and rejects vendor billing and revoked access', function () {
    $otherVendor = Vendor::query()->create(['name' => 'Other', 'status' => 'active']);
    $otherUser = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $otherUser->id, 'role_id' => Role::findByName('vendor-wifi', 'web')->id, 'scope_type' => 'vendor', 'area_id' => null, 'vendor_id' => $otherVendor->id]);
    $path = '/api/wifi/customers/'.$this->customer->public_id;
    $this->actingAs($this->vendorUser)->getJson($path)->assertOk()->assertJsonPath('data.household_id', $this->household->public_id)->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.kk');
    $this->getJson('/api/wifi/customers')->assertOk()->assertJsonCount(1, 'data.data');
    $this->postJson($path.'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'vendor-bill-key'])->assertForbidden();
    $this->actingAs($otherUser)->getJson($path)->assertNotFound();
    $this->getJson('/api/wifi/customers?vendor_id='.$this->vendor->public_id)->assertOk()->assertJsonCount(0, 'data.data');
    $this->vendorAssignment->update(['status' => 'revoked']);
    $this->actingAs($this->vendorUser)->getJson($path)->assertNotFound();
});

it('limits residents to their own household and treasurers to their area', function () {
    $citizen = User::factory()->create();
    $resident = app(PopulationService::class)->createResident($this->admin, ['name' => 'Resident', 'household_id' => $this->household->public_id, 'relationship' => 'head']);
    app(PopulationService::class)->account($this->admin, $resident, 'wifi-resident-account', ['user_id' => $citizen->public_id]);
    $path = '/api/wifi/customers/'.$this->customer->public_id;
    $this->actingAs($citizen)->getJson($path)->assertOk();
    $this->getJson('/api/wifi/packages')->assertOk()->assertJsonCount(1, 'data.data');
    $this->postJson($path.'/end', ['ends_on' => '2026-10-01'], ['Idempotency-Key' => 'resident-end-key'])->assertForbidden();
    $other = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $other->id, 'role_id' => Role::findByName('bendahara-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => Area::factory()->rt()->create()->id]);
    $this->actingAs($other)->getJson($path)->assertNotFound();
    $this->postJson($path.'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'cross-area-key'])->assertForbidden();
    $otherHousehold = Household::factory()->create();
    $this->actingAs($this->admin)->postJson('/api/wifi/customers', [...$this->customerData, 'household_id' => $otherHousehold->public_id], ['Idempotency-Key' => 'cross-package-key'])->assertUnprocessable();
});

it('honors activation, termination, period close and account scope revocation on replay', function () {
    $path = '/api/wifi/customers/'.$this->customer->public_id;
    $this->postJson($path.'/bill', ['period' => '2026-08'], ['Idempotency-Key' => 'before-activation-key'])->assertConflict();
    $this->postJson($path.'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'bill-september-key'])->assertCreated();
    $this->postJson($path.'/end', ['ends_on' => '2026-09-15'], ['Idempotency-Key' => 'end-partial-key'])->assertUnprocessable();
    $this->postJson($path.'/end', ['ends_on' => '2026-10-01'], ['Idempotency-Key' => 'end-wifi-key'])->assertOk();
    $this->postJson($path.'/bill', ['period' => '2026-10'], ['Idempotency-Key' => 'after-termination-key'])->assertConflict();
    $this->postJson('/api/wifi/customers', [...$this->customerData, 'starts_on' => '2026-10-01'], ['Idempotency-Key' => 'new-customer-key'])->assertCreated();
    $this->billing->close($this->treasurer, 'close-wifi-period', ['area_id' => $this->rt->public_id, 'period' => '2026-09']);
    $this->postJson($path.'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'existing-closed-key'])->assertCreated();
    RoleAssignment::query()->where('user_id', $this->treasurer->id)->update(['status' => 'revoked']);
    $this->postJson($path.'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'bill-september-key'])->assertForbidden();
});

it('does not leave a bill or idempotency result after missing tariff and denies moved households', function () {
    DB::connection('rukun')->table('tariffs')->where('payment_type_id', $this->type->id)->update(['ends_at' => '2026-09-01']);
    $path = '/api/wifi/customers/'.$this->customer->public_id.'/bill';
    $this->postJson($path, ['period' => '2026-09'], ['Idempotency-Key' => 'no-tariff-key'])->assertNotFound();
    expect(WifiBill::query()->count())->toBe(0)->and(Invoice::query()->count())->toBe(0)
        ->and(DB::connection('rukun')->table('billing_requests')->where('request_key', 'no-tariff-key')->count())->toBe(0);
    $this->billing->tariff($this->treasurer, 'fixed-tariff-key', $this->type, ['starts_at' => '2026-09-01', 'amount' => 175000]);
    $this->postJson($path, ['period' => '2026-09'], ['Idempotency-Key' => 'no-tariff-key'])->assertCreated();
    $this->household->update(['area_id' => Area::factory()->rt()->create()->id]);
    $this->postJson($path, ['period' => '2026-10'], ['Idempotency-Key' => 'moved-household-key'])->assertConflict();
});

it('blocks new billing in closed periods and keeps issued bill links immutable', function () {
    $bill = $this->wifi->bill($this->treasurer, 'immutable-bill-key', $this->customer, ['period' => '2026-09']);
    try {
        DB::connection('rukun')->table('wifi_bills')->where('public_id', $bill['public_id'])->delete();
        $this->fail('WiFi bill history must be immutable.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('P0001');
    }
    $this->billing->close($this->treasurer, 'wifi-close-key', ['area_id' => $this->rt->public_id, 'period' => '2026-09']);
    $household = Household::factory()->create(['area_id' => $this->rt->id]);
    $created = $this->wifi->customer($this->treasurer, 'second-household-key', [...$this->customerData, 'household_id' => $household->public_id]);
    $this->postJson('/api/wifi/customers/'.$created['public_id'].'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'closed-wifi-key'])->assertConflict();
    expect(WifiBill::query()->count())->toBe(1)->and(Invoice::query()->count())->toBe(1);
});

it('hides inactive vendors and disallows new invoices or subscriptions through them', function () {
    $this->vendor->update(['status' => 'inactive']);
    $this->actingAs($this->vendorUser)->getJson('/api/wifi/customers')->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($this->treasurer)->postJson('/api/wifi/customers/'.$this->customer->public_id.'/bill', ['period' => '2026-09'], ['Idempotency-Key' => 'inactive-vendor-key'])->assertConflict();
    $other = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->postJson('/api/wifi/customers', [...$this->customerData, 'household_id' => $other->public_id], ['Idempotency-Key' => 'inactive-customer-key'])->assertConflict();
});

it('serializes simultaneous generation into one invoice and one immutable bill', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    $actorId = $this->treasurer->id;
    $customerId = $this->customer->id;
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork WiFi test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(WifiService::class)->bill(User::query()->findOrFail($actorId), 'simultaneous-wifi-'.$number, WifiCustomer::query()->findOrFail($customerId), ['period' => '2026-09']);
                exit(0);
            } catch (Throwable) {
                exit(30);
            }
        }
        $children[] = $pid;
    }
    $codes = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::purge('core');
    DB::purge('rukun');
    expect($codes)->toBe([0, 0])->and(WifiBill::query()->count())->toBe(1)->and(Invoice::query()->count())->toBe(1);
});
