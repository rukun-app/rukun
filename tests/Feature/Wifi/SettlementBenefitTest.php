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
use Modules\Billing\Services\BillingService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\PopulationService;
use Modules\Wifi\Models\GallonBenefit;
use Modules\Wifi\Models\GallonClaim;
use Modules\Wifi\Models\GallonEntry;
use Modules\Wifi\Models\WifiBill;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiFinance;
use Modules\Wifi\Models\WifiPackage;
use Modules\Wifi\Services\GallonService;
use Modules\Wifi\Services\SettlementService;
use Modules\Wifi\Services\WifiService;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    $this->packageData = ['vendor_id' => $this->vendor->public_id, 'payment_type_id' => $this->type->public_id, 'name' => 'WiFi 20 Mbps', 'due_day' => 20, 'settle_day' => 25, 'allow_advance' => true];
    $this->wifi = app(WifiService::class);
    $package = $this->wifi->package($this->treasurer, 'wifi-package-key', $this->packageData);
    $this->package = WifiPackage::query()->where('public_id', $package['public_id'])->firstOrFail();
    $this->customerData = ['package_id' => $this->package->public_id, 'household_id' => $this->household->public_id, 'starts_on' => '2026-09-05'];
    $customer = $this->wifi->customer($this->treasurer, 'wifi-customer-key', $this->customerData);
    $this->customer = WifiCustomer::query()->where('public_id', $customer['public_id'])->firstOrFail();
    $issued = $this->wifi->bill($this->treasurer, 'settlement-bill-key', $this->customer, ['period' => '2026-09']);
    $this->bill = WifiBill::query()->where('public_id', $issued['public_id'])->firstOrFail();
    $this->invoice = Invoice::query()->findOrFail($this->bill->invoice_id);
    $this->citizen = User::factory()->create();
    $resident = app(PopulationService::class)->createResident($this->admin, ['name' => 'Citizen', 'household_id' => $this->household->public_id, 'relationship' => 'head']);
    app(PopulationService::class)->account($this->admin, $resident, 'galon-resident-link', ['user_id' => $this->citizen->public_id]);
    $this->actingAs($this->treasurer);
});

function wifiPayment($test, int $amount = 150000, string $date = '2026-09-24'): array
{
    return $test->billing->cash($test->treasurer, 'pay-'.Str::uuid(), ['household_id' => $test->household->public_id, 'amount' => $amount, 'paid_on' => $date, 'allocations' => [['invoice_id' => $test->invoice->public_id, 'amount' => $amount]]]);
}

function gallonGrant($test): GallonBenefit
{
    $result = app(GallonService::class)->grant($test->treasurer, 'grant-'.Str::uuid(), $test->bill);

    return GallonBenefit::query()->where('public_id', $result['public_id'])->firstOrFail();
}

function remitData(): array
{
    return ['posted_on' => '2026-09-29', 'channel' => 'cash', 'reference' => 'Vendor payment reference'];
}

it('reconciles remittance, advance and recovery with source receipts without recognizing operational income', function () {
    $receipt = wifiPayment($this, 50000);
    $path = '/api/wifi/bills/'.$this->bill->public_id;
    $remit = $this->postJson($path.'/remit', remitData(), ['Idempotency-Key' => 'remittance-key'])->assertCreated()->assertJsonPath('data.advance_outstanding', 100000)->assertJsonPath('data.receipt_funded', 50000)->json('data.public_id');
    $this->postJson($path.'/remit', remitData(), ['Idempotency-Key' => 'remittance-key'])->assertCreated()->assertJsonPath('data.public_id', $remit);
    $this->postJson($path.'/remit', remitData(), ['Idempotency-Key' => 'duplicate-remittance'])->assertConflict();
    $this->getJson('/api/wifi/finance/'.$remit)->assertOk()->assertJsonPath('data.source_receipts.0.receipt_id', $receipt['public_id'])->assertJsonPath('data.source_receipts.0.amount', 50000)->assertJsonCount(2, 'data.ledger_ids');
    $this->postJson($path.'/recover', [...remitData(), 'amount' => 100000], ['Idempotency-Key' => 'no-recovery-source'])->assertConflict();
    $this->postJson('/api/billing/receipts/'.$receipt['public_id'].'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'test'], ['Idempotency-Key' => 'source-reversal'])->assertConflict();
    wifiPayment($this, 100000, '2026-09-29');
    $this->postJson($path.'/recover', [...remitData(), 'amount' => 100000], ['Idempotency-Key' => 'recovery-key'])->assertCreated()->assertJsonPath('data.advance_outstanding', 0)->assertJsonPath('data.advance_recovered', 100000);
    $this->postJson($path.'/recover', [...remitData(), 'amount' => 1], ['Idempotency-Key' => 'over-recovery-key'])->assertConflict();
    $this->getJson($path.'/settlement')->assertOk()->assertJsonPath('data.eligible', false)->assertJsonPath('data.remitted', 150000);
    expect((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and((int) LedgerEntry::query()->where('fund_classification', 'operational')->sum('amount'))->toBe(0)
        ->and(LedgerEntry::query()->where('fund_classification', 'operational')->where('kind', 'income')->count())->toBe(0)
        ->and(WifiFinance::query()->count())->toBe(2);
    expect(fn () => DB::connection('rukun')->table('wifi_finance')->update(['reference' => 'tamper']))->toThrow(QueryException::class);
});

it('reverses recovery before remittance and protects linked cashbook entries and closed months', function () {
    wifiPayment($this, 50000);
    $service = app(SettlementService::class);
    $remit = $service->write($this->treasurer, 'remit-for-reversal', $this->bill, 'remit', remitData());
    wifiPayment($this, 100000, '2026-09-29');
    $recovery = $service->write($this->treasurer, 'recover-for-reversal', $this->bill, 'recover', [...remitData(), 'amount' => 100000]);
    $reverse = ['posted_on' => '2026-09-29', 'reference' => 'Correction'];
    $this->postJson('/api/wifi/finance/'.$remit['public_id'].'/reverse', $reverse, ['Idempotency-Key' => 'wrong-reverse-order'])->assertConflict();
    $linked = DB::connection('rukun')->table('wifi_finance_ledger')->first();
    $entry = LedgerEntry::query()->findOrFail($linked->ledger_id);
    $this->postJson('/api/billing/ledger/'.$entry->public_id.'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'bypass'], ['Idempotency-Key' => 'direct-ledger-reverse'])->assertConflict();
    $this->postJson('/api/wifi/finance/'.$recovery['public_id'].'/reverse', $reverse, ['Idempotency-Key' => 'reverse-recovery'])->assertOk()->assertJsonPath('data.advance_outstanding', 100000);
    $this->postJson('/api/wifi/finance/'.$remit['public_id'].'/reverse', $reverse, ['Idempotency-Key' => 'reverse-remittance'])->assertOk()->assertJsonPath('data.remitted', 0);
    expect((int) LedgerEntry::query()->sum('amount'))->toBe(150000)->and(WifiFinance::query()->count())->toBe(4);
    $this->billing->close($this->treasurer, 'close-settlement-period', ['area_id' => $this->rt->public_id, 'period' => '2026-09']);
    $this->postJson('/api/wifi/bills/'.$this->bill->public_id.'/remit', remitData(), ['Idempotency-Key' => 'remit-closed-period'])->assertConflict();
});

it('requires cutoff closure and obeys the disabled advance policy', function () {
    $this->package->update(['allow_advance' => false]);
    $path = '/api/wifi/bills/'.$this->bill->public_id;
    $this->postJson($path.'/remit', remitData(), ['Idempotency-Key' => 'advance-disabled', 'Accept-Language' => 'id'])->assertConflict()->assertJsonPath('message', 'Talangan dinonaktifkan untuk paket ini.');
    wifiPayment($this, 50000);
    wifiPayment($this, 100000, '2026-09-25');
    $this->travelTo(now()->setDate(2026, 9, 25));
    $this->actingAs($this->admin);
    $this->postJson($path.'/grant', [], ['Idempotency-Key' => 'grant-too-early'])->assertConflict();
    $this->travelTo(now()->setDate(2026, 9, 29));
    $this->actingAs($this->treasurer);
    $this->postJson($path.'/grant', [], ['Idempotency-Key' => 'grant-after-cutoff'])->assertCreated();
    $this->postJson($path.'/remit', remitData(), ['Idempotency-Key' => 'remit-fully-paid'])->assertCreated()->assertJsonPath('data.advance_issued', 0);
    expect(app(SettlementService::class)->eligible($this->bill))->toBeTrue();
});

it('reserves and records deliveries but consumes quota finally only after independent resident confirmation', function () {
    wifiPayment($this);
    $benefit = gallonGrant($this);
    $again = gallonGrant($this);
    expect($again->id)->toBe($benefit->id)->and($benefit->available)->toBe(10);
    $path = '/api/wifi/benefits/'.$benefit->public_id.'/reserve';
    $claimId = $this->actingAs($this->vendorUser)->postJson($path, ['quantity' => 4, 'reference' => 'delivery-001'], ['Idempotency-Key' => 'reserve-gallons'])->assertCreated()->json('data.public_id');
    $this->postJson($path, ['quantity' => 4, 'reference' => 'delivery-001'], ['Idempotency-Key' => 'reserve-natural-replay'])->assertCreated()->assertJsonPath('data.public_id', $claimId);
    $this->postJson($path, ['quantity' => 7, 'reference' => 'delivery-002'], ['Idempotency-Key' => 'over-reserve-key'])->assertConflict();
    $this->postJson('/api/wifi/claims/'.$claimId.'/confirm', [], ['Idempotency-Key' => 'vendor-confirm-key'])->assertForbidden();
    $this->actingAs($this->citizen)->postJson('/api/wifi/claims/'.$claimId.'/confirm', [], ['Idempotency-Key' => 'undelivered-confirm'])->assertConflict();
    $this->actingAs($this->vendorUser)->postJson('/api/wifi/claims/'.$claimId.'/deliver', [], ['Idempotency-Key' => 'record-delivery'])->assertOk()->assertJsonPath('data.status', 'delivered');
    expect($benefit->fresh()->available)->toBe(6)->and($benefit->fresh()->reserved)->toBe(4)->and($benefit->fresh()->confirmed)->toBe(0);
    $this->actingAs($this->citizen)->postJson('/api/wifi/claims/'.$claimId.'/confirm', [], ['Idempotency-Key' => 'resident-confirm'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->postJson('/api/wifi/claims/'.$claimId.'/confirm', [], ['Idempotency-Key' => 'resident-confirm'])->assertOk();
    expect($benefit->fresh()->confirmed)->toBe(4)->and($benefit->fresh()->reserved)->toBe(0)->and(GallonEntry::query()->count())->toBe(4);
    $this->getJson('/api/wifi/gallon-ledger?benefit_id='.$benefit->public_id)->assertOk()->assertJsonCount(4, 'data.data');
});

it('preserves reversal history and releases receipt reversal only after reversing benefits and claims', function () {
    $receipt = wifiPayment($this);
    $benefit = gallonGrant($this);
    $gallons = app(GallonService::class);
    $created = $gallons->reserve($this->vendorUser, 'reverse-reserve-key', $benefit, ['quantity' => 3, 'reference' => 'delivery-reverse']);
    $claim = GallonClaim::query()->where('public_id', $created['public_id'])->firstOrFail();
    $gallons->claim($this->vendorUser, 'reverse-deliver-key', $claim, 'deliver', []);
    $gallons->claim($this->citizen, 'reverse-confirm-key', $claim, 'confirm', []);
    $this->postJson('/api/wifi/benefits/'.$benefit->public_id.'/reverse', ['reason' => 'Correction'], ['Idempotency-Key' => 'reverse-grant-before-claim'])->assertConflict();
    $this->postJson('/api/billing/receipts/'.$receipt['public_id'].'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Correction'], ['Idempotency-Key' => 'reverse-protected-receipt'])->assertConflict();
    $this->actingAs($this->vendorUser)->postJson('/api/wifi/claims/'.$claim->public_id.'/reverse', ['reason' => 'Correction'], ['Idempotency-Key' => 'vendor-reverse-final'])->assertForbidden();
    $this->actingAs($this->treasurer)->postJson('/api/wifi/claims/'.$claim->public_id.'/reverse', ['reason' => 'Correction'], ['Idempotency-Key' => 'manager-reverse-final'])->assertOk();
    expect($benefit->fresh()->available)->toBe(10)->and($benefit->fresh()->confirmed)->toBe(0);
    $this->postJson('/api/wifi/benefits/'.$benefit->public_id.'/reverse', ['reason' => 'Correction'], ['Idempotency-Key' => 'reverse-grant-final'])->assertOk();
    $this->postJson('/api/billing/receipts/'.$receipt['public_id'].'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Correction'], ['Idempotency-Key' => 'reverse-protected-receipt'])->assertOk();
    expect($benefit->fresh()->available)->toBe(0)->and(GallonEntry::query()->count())->toBe(6)->and(app(SettlementService::class)->eligible($this->bill))->toBeFalse();
});

it('expires unconfirmed deliveries without final consumption and keeps the append-only ledger reconciled', function () {
    wifiPayment($this);
    $benefit = gallonGrant($this);
    $created = app(GallonService::class)->reserve($this->vendorUser, 'expire-reserve-key', $benefit, ['quantity' => 6, 'reference' => 'expire-delivery']);
    $claim = GallonClaim::query()->where('public_id', $created['public_id'])->firstOrFail();
    app(GallonService::class)->claim($this->vendorUser, 'expire-deliver-key', $claim, 'deliver', []);
    $this->travelTo(now()->setDate(2026, 10, 26));
    $this->artisan('wifi:expire-benefits')->assertSuccessful();
    $this->artisan('wifi:expire-benefits')->assertSuccessful();
    expect($benefit->fresh()->available)->toBe(0)->and($benefit->fresh()->reserved)->toBe(0)->and($benefit->fresh()->confirmed)->toBe(0)->and($claim->fresh()->status)->toBe('expired');
    foreach (['available', 'reserved', 'confirmed'] as $field) {
        expect((int) GallonEntry::query()->sum($field.'_delta'))->toBe($benefit->fresh()->{$field});
    }
    $this->actingAs($this->citizen)->postJson('/api/wifi/claims/'.$claim->public_id.'/confirm', [], ['Idempotency-Key' => 'expired-confirm-key'])->assertConflict();
    expect(fn () => DB::connection('rukun')->table('gallon_entries')->delete())->toThrow(QueryException::class);
});

it('isolates finance and benefit data across vendors and households', function () {
    wifiPayment($this);
    $benefit = gallonGrant($this);
    $this->actingAs($this->vendorUser)->getJson('/api/wifi/bills/'.$this->bill->public_id.'/settlement')->assertForbidden();
    $this->getJson('/api/wifi/finance')->assertOk()->assertJsonCount(0, 'data.data');
    $this->getJson('/api/wifi/benefits/'.$benefit->public_id)->assertOk();
    $other = User::factory()->create();
    $this->actingAs($other)->getJson('/api/wifi/benefits/'.$benefit->public_id)->assertNotFound();
    $this->postJson('/api/wifi/benefits/'.$benefit->public_id.'/reserve', ['quantity' => 1, 'reference' => 'cross-vendor'], ['Idempotency-Key' => 'cross-vendor-reserve'])->assertForbidden();
    $otherVendor = Vendor::query()->create(['name' => 'Other Gallon Vendor', 'status' => 'active']);
    RoleAssignment::factory()->create(['user_id' => $other->id, 'role_id' => Role::findByName('vendor-wifi', 'web')->id, 'scope_type' => 'vendor', 'area_id' => null, 'vendor_id' => $otherVendor->id]);
    $this->getJson('/api/wifi/benefits/'.$benefit->public_id)->assertNotFound();
    $this->postJson('/api/wifi/benefits/'.$benefit->public_id.'/reserve', ['quantity' => 1, 'reference' => 'cross-vendor'], ['Idempotency-Key' => 'cross-vendor-reserve'])->assertForbidden();
    $this->vendorAssignment->update(['status' => 'revoked']);
    $this->actingAs($this->vendorUser)->getJson('/api/wifi/benefits/'.$benefit->public_id)->assertNotFound();
});

it('serializes concurrent reservations and cannot consume more than the granted quota', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    wifiPayment($this);
    $benefit = gallonGrant($this);
    $actorId = $this->vendorUser->id;
    $benefitId = $benefit->id;
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork gallon test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(GallonService::class)->reserve(User::query()->findOrFail($actorId), 'concurrent-reserve-'.$number, GallonBenefit::query()->findOrFail($benefitId), ['quantity' => 7, 'reference' => 'concurrent-'.$number]);
                exit(0);
            } catch (HttpException $exception) {
                exit($exception->getStatusCode() === 409 ? 10 : 20);
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
    sort($codes);
    expect($codes)->toBe([0, 10])->and($benefit->fresh()->available)->toBe(3)->and($benefit->fresh()->reserved)->toBe(7)->and(GallonClaim::query()->count())->toBe(1);
});

it('denies self confirmation even when the vendor is also a household member and rechecks replay authorization', function () {
    wifiPayment($this);
    $benefit = gallonGrant($this);
    RoleAssignment::factory()->create(['user_id' => $this->citizen->id, 'role_id' => Role::findByName('vendor-wifi', 'web')->id, 'scope_type' => 'vendor', 'area_id' => null, 'vendor_id' => $this->vendor->id]);
    $gallons = app(GallonService::class);
    $result = $gallons->reserve($this->citizen, 'self-reserve-key', $benefit, ['quantity' => 1, 'reference' => 'self-delivery']);
    $claim = GallonClaim::query()->where('public_id', $result['public_id'])->firstOrFail();
    $gallons->claim($this->citizen, 'self-deliver-key', $claim, 'deliver', []);
    $this->actingAs($this->citizen)->postJson('/api/wifi/claims/'.$claim->public_id.'/confirm', [], ['Idempotency-Key' => 'self-confirm-key'])->assertForbidden();
    RoleAssignment::query()->where('user_id', $this->citizen->id)->update(['status' => 'revoked']);
    $this->postJson('/api/wifi/benefits/'.$benefit->public_id.'/reserve', ['quantity' => 1, 'reference' => 'self-delivery'], ['Idempotency-Key' => 'self-reserve-key'])->assertForbidden();
});

it('rejects late payment benefits and excludes reversed timely receipts from eligibility', function () {
    $receipt = wifiPayment($this, 100000);
    wifiPayment($this, 50000, '2026-09-26');
    $path = '/api/wifi/bills/'.$this->bill->public_id;
    $this->postJson($path.'/grant', [], ['Idempotency-Key' => 'late-grant-key'])->assertConflict();
    $this->postJson('/api/billing/receipts/'.$receipt['public_id'].'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Correction'], ['Idempotency-Key' => 'reverse-ungranted-receipt'])->assertOk();
    $this->getJson($path.'/settlement')->assertOk()->assertJsonPath('data.paid_at_cutoff', 0)->assertJsonPath('data.eligible', false);
    expect(GallonBenefit::query()->count())->toBe(0);
});

it('serializes concurrent remittances so a vendor cannot be paid twice for the same invoice', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    wifiPayment($this);
    $actorId = $this->treasurer->id;
    $billId = $this->bill->id;
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork remittance test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(SettlementService::class)->write(User::query()->findOrFail($actorId), 'concurrent-remit-'.$number, WifiBill::query()->findOrFail($billId), 'remit', remitData());
                exit(0);
            } catch (HttpException $exception) {
                exit($exception->getStatusCode() === 409 ? 10 : 20);
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
    sort($codes);
    expect($codes)->toBe([0, 10])->and(WifiFinance::query()->count())->toBe(1)->and((int) LedgerEntry::query()->sum('amount'))->toBe(0);
});

it('requires manager authority to replay reversal of a confirmed claim after a role downgrade', function () {
    wifiPayment($this);
    $benefit = gallonGrant($this);
    $gallons = app(GallonService::class);
    $created = $gallons->reserve($this->vendorUser, 'downgrade-reserve', $benefit, ['quantity' => 2, 'reference' => 'downgrade-delivery']);
    $claim = GallonClaim::query()->where('public_id', $created['public_id'])->firstOrFail();
    $gallons->claim($this->vendorUser, 'downgrade-deliver', $claim, 'deliver', []);
    $gallons->claim($this->citizen, 'downgrade-confirm', $claim, 'confirm', []);
    $managerAssignment = RoleAssignment::factory()->create(['user_id' => $this->vendorUser->id, 'role_id' => Role::findByName('bendahara-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->rt->id]);
    $data = ['reason' => 'Correct confirmed delivery'];
    $this->actingAs($this->vendorUser)->postJson('/api/wifi/claims/'.$claim->public_id.'/reverse', $data, ['Idempotency-Key' => 'downgrade-reverse'])->assertOk();
    $managerAssignment->update(['status' => 'revoked']);
    $this->postJson('/api/wifi/claims/'.$claim->public_id.'/reverse', $data, ['Idempotency-Key' => 'downgrade-reverse'])->assertForbidden();
});
