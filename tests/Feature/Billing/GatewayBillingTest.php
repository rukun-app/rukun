<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\GatewayCheckout;
use Modules\Billing\Models\GatewaySettlement;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Models\Tariff;
use Modules\Billing\Services\BillingReport;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\GatewayBilling;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\PopulationService;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 2)->setTime(12, 0));
    Cache::store('redis')->getStore()->setPrefix('gateway-test:'.Str::uuid().':');
    config()->set(['broadcasting.default' => 'null', 'payments.midtrans.server_key' => 'gateway-test-key', 'payments.midtrans.production' => false]);
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);
    config()->set('runtime-settings.midtrans_enabled', true);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->rt = Area::factory()->rt()->create();
    $this->household = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->citizen = User::factory()->create();
    $resident = app(PopulationService::class)->createResident($this->admin, ['name' => 'Gateway Payer', 'household_id' => $this->household->public_id, 'relationship' => 'head']);
    app(PopulationService::class)->account($this->admin, $resident, 'link-gateway-payer', ['user_id' => $this->citizen->public_id]);
    $type = PaymentType::query()->create(['area_id' => $this->rt->id, 'code' => 'IURAN', 'name' => 'Iuran', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational']);
    $tariff = Tariff::query()->create(['payment_type_id' => $type->id, 'amount' => 100000, 'starts_at' => '2026-01-01']);
    $this->invoice = Invoice::query()->create(['household_id' => $this->household->id, 'area_id' => $this->rt->id, 'payment_type_id' => $type->id, 'tariff_id' => $tariff->id, 'period' => '2026-10-01', 'amount' => 100000, 'due_date' => '2026-10-28', 'settle_by' => '2026-10-28', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational', 'state' => 'issued']);
    gatewayFake(['*/snap/v1/transactions' => Http::response(['token' => 'demo-checkout-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/demo'])]);
});

function gatewayFake(array $responses): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($responses + ['*/snap/v1/transactions' => Http::response(['token' => 'demo-checkout-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/demo'])]);
}

function gatewayPayload(Payment $payment, string $status = 'settlement'): array
{
    $data = ['order_id' => $payment->provider_order_id, 'status_code' => $status === 'settlement' ? '200' : '202', 'gross_amount' => $payment->amount.'.00', 'transaction_status' => $status, 'transaction_id' => 'TX-'.$payment->public_id, 'payment_type' => 'qris', 'currency' => 'IDR', 'settlement_time' => '2026-10-02 11:00:00'];
    $data['signature_key'] = hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].'gateway-test-key');

    return $data;
}

function gatewayCheckout($test, string $key = 'checkout-key-001'): GatewayCheckout
{
    return app(GatewayBilling::class)->checkout($test->citizen, $test->invoice, $key);
}

function gatewaySettle($test, GatewayCheckout $checkout): Payment
{
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    $payload = gatewayPayload($payment);
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response($payload)]);
    $test->postJson('/api/payments/webhooks/midtrans', $payload)->assertOk();

    return $payment->refresh();
}

it('derives the outstanding amount and enforces scope and idempotency before creating QRIS', function () {
    Sanctum::actingAs($this->citizen);
    $url = '/api/billing/invoices/'.$this->invoice->public_id.'/checkout';
    $this->postJson($url, [], ['Idempotency-Key' => 'short'])->assertUnprocessable();
    $this->postJson($url, ['amount' => 1], ['Idempotency-Key' => 'checkout-request'])->assertUnprocessable();
    app(BillingService::class)->cash($this->admin, 'prior-cash-payment', ['household_id' => $this->household->public_id, 'amount' => 20000, 'paid_on' => '2026-10-02']);
    $first = $this->postJson($url, [], ['Idempotency-Key' => 'checkout-request'])->assertCreated()->assertJsonPath('data.amount', 80000)->assertJsonPath('data.payment_status', 'pending')->json('data.public_id');
    $this->postJson($url, [], ['Idempotency-Key' => 'checkout-request'])->assertCreated()->assertJsonPath('data.public_id', $first);
    $this->postJson($url, [], ['Idempotency-Key' => 'another-request'])->assertConflict();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['transaction_details']['gross_amount'] === 80000 && $request['enabled_payments'] === ['other_qris'] && $request['expiry']['duration'] === 15 && isset($request['expiry']['start_time']));
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($url, [], ['Idempotency-Key' => 'outsider-request'])->assertForbidden();
    $this->getJson('/api/billing/gateway-checkouts/'.$first)->assertForbidden();
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/payments', ['reference_type' => 'billing.gateway', 'reference_id' => $this->invoice->public_id, 'amount' => 1], ['Idempotency-Key' => 'generic-checkout'])->assertUnprocessable();
});

it('reserves the invoice against cash, cancellation and period close', function () {
    gatewayCheckout($this);
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/billing/receipts/cash', ['household_id' => $this->household->public_id, 'amount' => 100000, 'paid_on' => '2026-10-02'], ['Idempotency-Key' => 'cash-during-qris'])->assertConflict();
    $this->postJson('/api/billing/invoices/'.$this->invoice->public_id.'/cancel', [], ['Idempotency-Key' => 'cancel-during-qris'])->assertConflict();
    $this->postJson('/api/billing/periods/close', ['area_id' => $this->rt->public_id, 'period' => '2026-10'], ['Idempotency-Key' => 'close-during-qris'])->assertConflict();
    expect(Receipt::query()->count())->toBe(0);
});

it('verifies provider status and posts exactly one receipt allocation and gross ledger using provider time', function () {
    $checkout = gatewayCheckout($this);
    $payment = gatewaySettle($this, $checkout);
    $payload = gatewayPayload($payment);
    $this->postJson('/api/payments/webhooks/midtrans', $payload)->assertOk();
    $this->postJson('/api/payments/webhooks/midtrans', gatewayPayload($payment, 'expire'))->assertOk();
    expect($checkout->fresh()->status)->toBe('completed')->and(Receipt::query()->count())->toBe(1)
        ->and(DB::connection('rukun')->table('receipt_allocations')->count())->toBe(1)
        ->and((int) LedgerEntry::query()->where('kind', 'income')->sum('amount'))->toBe(100000)
        ->and(app(BillingReport::class)->paid($this->invoice))->toBe(100000)
        ->and($payment->paid_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'))->toBe('2026-10-02 11:00:00')
        ->and(Receipt::query()->first()->paid_at->equalTo($payment->paid_at))->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/status'));
});

it('ignores forged callback state and rejects invalid signatures and invalid confirmed timestamps', function () {
    $checkout = gatewayCheckout($this);
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    $pending = gatewayPayload($payment, 'pending');
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response($pending)]);
    $payload = gatewayPayload($payment);
    $this->postJson('/api/payments/webhooks/midtrans', $payload)->assertOk();
    expect(Receipt::query()->count())->toBe(0)->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    $this->postJson('/api/payments/webhooks/midtrans', [...$payload, 'signature_key' => str_repeat('0', 128)])->assertUnauthorized();
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response([...$payload, 'settlement_time' => 'bad-time'])]);
    $this->postJson('/api/payments/webhooks/midtrans', $payload)->assertUnprocessable();
    expect(Receipt::query()->count())->toBe(0);
});

it('only releases expiry after provider confirmation and supports a new order afterwards', function () {
    $checkout = gatewayCheckout($this);
    $this->travel(20)->minutes();
    expect(GatewayBilling::reserved($this->invoice))->toBe(100000);
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response(gatewayPayload($payment, 'expire'))]);
    app(GatewayBilling::class)->reconcile($checkout);
    expect($checkout->fresh()->status)->toBe('released')->and(GatewayBilling::reserved($this->invoice))->toBe(0);
    $new = gatewayCheckout($this, 'new-order-after-expiry');
    expect($new->payment_id)->not->toBe($checkout->payment_id);
    gatewaySettle($this, $checkout);
    expect($checkout->fresh()->review_reason)->toBe('late_success_after_release')->and(Receipt::query()->count())->toBe(0)->and(GatewayBilling::reserved($this->invoice))->toBe(100000);
});

it('keeps ambiguous checkout attempts reserved and never retries a charge with a new order', function () {
    gatewayFake(['*/snap/v1/transactions' => Http::response([], 503)]);
    $checkout = gatewayCheckout($this);
    $same = gatewayCheckout($this);
    expect($same->id)->toBe($checkout->id)->and(Payment::query()->count())->toBe(1)->and(Payment::query()->first()->status)->toBe(PaymentStatus::ProviderUnknown)->and(GatewayBilling::reserved($this->invoice))->toBe(100000);
    Http::assertSentCount(1);
    gatewaySettle($this, $checkout);
    expect($checkout->fresh()->status)->toBe('completed');
});

it('recovers a committed payment whose callback could not post its billing receipt', function () {
    $checkout = gatewayCheckout($this);
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()->subMinute(), 'payment_type' => 'qris']);
    $this->artisan('billing:reconcile-gateway')->assertSuccessful();
    $this->artisan('billing:reconcile-gateway')->assertSuccessful();
    expect($checkout->fresh()->status)->toBe('completed')->and(Receipt::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('records actual gateway fee separately from gross receipt and preserves pass through funds', function () {
    $this->invoice->update(['fund_classification' => 'pass_through']);
    $checkout = gatewayCheckout($this);
    gatewaySettle($this, $checkout);
    Sanctum::actingAs($this->citizen);
    $url = '/api/billing/gateway-checkouts/'.$checkout->public_id.'/settlement';
    $data = ['fee' => 700, 'settled_on' => '2026-10-02', 'statement_reference' => 'sandbox-statement-1'];
    $this->postJson($url, $data, ['Idempotency-Key' => 'settlement-fee-key'])->assertForbidden();
    Sanctum::actingAs($this->admin);
    $this->postJson($url, $data, ['Idempotency-Key' => 'settlement-fee-key'])->assertCreated()->assertJsonPath('data.settlement.gross', 100000)->assertJsonPath('data.settlement.net', 99300);
    $this->postJson($url, $data, ['Idempotency-Key' => 'settlement-fee-key'])->assertCreated();
    $this->postJson($url, [...$data, 'fee' => 800], ['Idempotency-Key' => 'settlement-fee-key'])->assertConflict();
    expect(GatewaySettlement::query()->count())->toBe(1)->and((int) LedgerEntry::query()->where('fund_classification', 'pass_through')->sum('amount'))->toBe(100000)->and((int) LedgerEntry::query()->where('fund_classification', 'operational')->sum('amount'))->toBe(-700)->and((int) LedgerEntry::query()->sum('amount'))->toBe(99300);
});

it('rechecks revoked membership on idempotent replay without preventing receipt posting', function () {
    $checkout = gatewayCheckout($this);
    DB::connection('rukun')->table('household_memberships')->update(['ends_at' => now()]);
    DB::connection('rukun')->table('residents')->update(['household_id' => null]);
    Sanctum::actingAs($this->citizen);
    $this->postJson('/api/billing/invoices/'.$this->invoice->public_id.'/checkout', [], ['Idempotency-Key' => 'checkout-key-001'])->assertForbidden();
    gatewaySettle($this, $checkout);
    expect(Receipt::query()->count())->toBe(1);
});

it('serializes concurrent checkouts into one reservation and one provider order', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    $actorId = $this->citizen->id;
    $invoiceId = $this->invoice->id;
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork checkout test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(GatewayBilling::class)->checkout(User::query()->findOrFail($actorId), Invoice::query()->findOrFail($invoiceId), 'concurrent-checkout-'.$number);
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
    expect($codes)->toBe([0, 10])->and(GatewayCheckout::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1)->and(GatewayBilling::reserved($this->invoice))->toBe(100000);
});

it('handles simultaneous verified callbacks without double allocation', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    $checkout = gatewayCheckout($this);
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    $payload = gatewayPayload($payment);
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response($payload)]);
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork callback test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(PaymentManager::class)->handleNotification($payload);
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
    expect($codes)->toBe([0, 0])->and(Receipt::query()->count())->toBe(1)->and(app(BillingReport::class)->paid($this->invoice))->toBe(100000);
});

it('retains reservations on status outages and exposes reconciliation only in finance scope', function () {
    $checkout = gatewayCheckout($this);
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response([], 503)]);
    Sanctum::actingAs($this->citizen);
    $this->postJson('/api/billing/gateway-checkouts/'.$checkout->public_id.'/reconcile', [], ['Idempotency-Key' => 'reconcile-outage'])->assertStatus(503);
    expect(GatewayBilling::reserved($this->invoice))->toBe(100000);
    $this->getJson('/api/billing/gateway-checkouts')->assertOk()->assertJsonCount(0, 'data.data');
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/billing/gateway-checkouts')->assertOk()->assertJsonCount(1, 'data.data');
});

it('preserves provider refund history without silently reversing the receipt or settlement', function () {
    $checkout = gatewayCheckout($this);
    $payment = gatewaySettle($this, $checkout);
    $payload = gatewayPayload($payment, 'refund');
    gatewayFake(['*/'.$payment->provider_order_id.'/status' => Http::response($payload)]);
    $this->postJson('/api/payments/webhooks/midtrans', $payload)->assertOk();
    expect($checkout->fresh()->review_reason)->toBe('refunded')->and(app(BillingReport::class)->paid($this->invoice))->toBe(100000);
    Sanctum::actingAs($this->admin);
    $receipt = Receipt::query()->firstOrFail();
    $this->postJson('/api/billing/receipts/'.$receipt->public_id.'/reverse', ['posted_on' => '2026-10-02', 'reason' => 'No silent refund'], ['Idempotency-Key' => 'manual-refund-key'])->assertConflict();
    $this->postJson('/api/billing/gateway-checkouts/'.$checkout->public_id.'/settlement', ['fee' => 0, 'settled_on' => '2026-10-02', 'statement_reference' => 'refunded-order'], ['Idempotency-Key' => 'refunded-settlement'])->assertConflict();
});

it('expires an unattempted order without HTTP and rejects reuse of a key for another invoice', function () {
    $checkout = gatewayCheckout($this);
    $other = $this->invoice->replicate(['public_id']);
    $other->public_id = (string) Str::uuid();
    $other->subject = 'other-invoice';
    $other->save();
    Sanctum::actingAs($this->citizen);
    $this->postJson('/api/billing/invoices/'.$other->public_id.'/checkout', [], ['Idempotency-Key' => 'checkout-key-001'])->assertConflict();
    $this->getJson('/api/billing/invoices/'.$this->invoice->public_id)->assertOk()->assertJsonPath('data.reserved_amount', 100000)->assertJsonPath('data.payable_amount', 0);
    $payment = Payment::query()->findOrFail($checkout->payment_id);
    $payment->update(['status' => PaymentStatus::Creating, 'checkout_url' => null]);
    $this->travel(20)->minutes();
    app(GatewayBilling::class)->reconcile($checkout);
    expect($checkout->fresh()->status)->toBe('released')->and($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    Http::assertSentCount(1);
});

it('keeps confirmed payments in review if their posting period was already closed', function () {
    $checkout = gatewayCheckout($this);
    AccountingPeriod::query()->create(['area_id' => $this->rt->id, 'period' => '2026-10-01', 'report' => [], 'closed_by' => $this->admin->id, 'closed_at' => now()]);
    gatewaySettle($this, $checkout);
    expect($checkout->fresh()->status)->toBe('review')->and($checkout->fresh()->review_reason)->toBe('closed_period')->and(Receipt::query()->count())->toBe(0)->and(GatewayBilling::reserved($this->invoice))->toBe(100000);
});
