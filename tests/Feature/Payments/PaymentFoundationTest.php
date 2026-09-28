<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentStatusChanged;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config()->set('runtime-settings.midtrans_enabled', true);
    config()->set('payments.midtrans.server_key', 'SB-Mid-server-test');
    config()->set('payments.midtrans.production', false);
    config()->set('payments.redirect_base_url', 'https://payments.example.test');
    cache()->forget('setting:payments.midtrans_enabled');
    cache()->forget('setting:payments.midtrans_timeout');
});

function midtransPayload(Payment $payment, array $overrides = []): array
{
    $payload = [
        'order_id' => $payment->provider_order_id,
        'transaction_id' => 'midtrans-transaction-1',
        'transaction_status' => 'settlement',
        'status_code' => '200',
        'gross_amount' => $payment->amount.'.00',
        'payment_type' => 'bank_transfer',
        'fraud_status' => 'accept',
    ];
    $payload = [...$payload, ...$overrides];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('payments.midtrans.server_key'));

    return $payload;
}

it('creates a generic Midtrans checkout through the payment manager', function () {
    Http::fake(['app.sandbox.midtrans.com/*' => Http::response([
        'token' => 'snap-token',
        'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/token',
    ], 201)]);
    $user = User::factory()->create();

    $payment = app(PaymentManager::class)->create(
        $user,
        'billing.invoice',
        'INV-1001',
        125000,
        ['name' => $user->name, 'email' => $user->email],
        ['purpose' => 'invoice'],
    );

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(125000)
        ->and($payment->reference_type)->toBe('billing.invoice')
        ->and($payment->checkout_url)->toContain('midtrans.com');
    Http::assertSent(fn ($request) => $request['transaction_details']['order_id'] === $payment->provider_order_id
        && $request['transaction_details']['gross_amount'] === 125000
        && $request['callbacks']['finish'] === 'https://payments.example.test/payments/finish'
        && $request->hasHeader('Authorization'));
});

it('renders localized public payment redirect pages without trusting their status', function () {
    $this->withHeader('Accept-Language', 'id')->get('/payments/finish?order_id=CORE-redirect-1')
        ->assertOk()
        ->assertSee('Pembayaran dikirim')
        ->assertSee('CORE-redirect-1');

    $this->withHeader('Accept-Language', 'en')->get('/payments/unfinish')
        ->assertOk()
        ->assertSee('Payment not completed');

    $this->get('/payments/error?order_id=%3Cscript%3Ealert(1)%3C%2Fscript%3E')
        ->assertOk()
        ->assertSee('Payment failed')
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('creates an idempotent checkout through the authorized API endpoint', function () {
    Http::fake(['app.sandbox.midtrans.com/*' => Http::response([
        'token' => 'api-snap-token',
        'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/api-token',
    ], 201)]);
    $user = User::factory()->create();
    $payload = [
        'reference_type' => 'testing.order',
        'reference_id' => 'ORDER-1001',
        'amount' => 105000,
        'metadata' => ['purpose' => 'sandbox checkout'],
    ];
    $key = 'payment-order-'.fake()->uuid();

    Sanctum::actingAs($user);
    $this->withHeader('Idempotency-Key', $key)->postJson('/api/payments', $payload)->assertForbidden();

    $user->givePermissionTo('payments.create');
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/payments', $payload)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false')
        ->assertJsonPath('data.reference.type', 'testing.order')
        ->assertJsonPath('data.reference.id', 'ORDER-1001')
        ->assertJsonPath('data.amount', 105000)
        ->assertJsonPath('data.status', 'pending');
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/payments', $payload)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Payment::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('requires an idempotency key when creating a payment checkout', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('payments.create');
    Sanctum::actingAs($user);

    $this->postJson('/api/payments', [
        'reference_type' => 'testing.order',
        'reference_id' => 'ORDER-1002',
        'amount' => 105000,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('idempotency_key');
});

it('rejects a successful HTTP response when the provider transaction is not available', function () {
    Http::fake(['api.sandbox.midtrans.com/*' => Http::response([
        'status_code' => '404',
        'status_message' => 'Transaction does not exist.',
        'id' => 'transaction-id',
    ])]);

    expect(fn () => app(PaymentGateway::class)->status('CORE-not-started'))
        ->toThrow(PaymentGatewayException::class);
});

it('verifies and processes duplicate Midtrans notifications idempotently', function () {
    $payment = Payment::query()->create([
        'public_id' => fake()->uuid(), 'user_id' => User::factory()->create()->id,
        'provider' => 'midtrans', 'provider_order_id' => 'CORE-notification-1',
        'reference_type' => 'billing.invoice', 'reference_id' => 'INV-1',
        'amount' => 50000, 'currency' => 'IDR', 'status' => PaymentStatus::Pending,
    ]);
    $payload = midtransPayload($payment);

    $this->postJson('/api/payments/webhooks/midtrans', $payload)->assertOk()->assertJsonPath('data.received', true);
    $this->postJson('/api/payments/webhooks/midtrans', $payload)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and(DB::table('payment_notifications')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event', 'payment.notification_processed')->count())->toBe(1)
        ->and(DB::table('user_events')->where('type', 'payment.updated')->count())->toBe(1);
});

it('acknowledges only signed Midtrans dashboard test notifications in Sandbox', function () {
    $payload = [
        'order_id' => 'payment_notif_test_G767444105_59fb0c37-e723-4501-ae21-4e1373e0c68a',
        'transaction_id' => fake()->uuid(),
        'transaction_status' => 'settlement',
        'status_code' => '200',
        'gross_amount' => '105000.00',
        'currency' => 'IDR',
    ];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('payments.midtrans.server_key'));

    $this->postJson('/api/payments/webhooks/midtrans', $payload)
        ->assertOk()
        ->assertJsonPath('data.received', true)
        ->assertJsonPath('data.test', true);

    expect(Payment::query()->count())->toBe(0);

    $payload['signature_key'] = str_repeat('0', 128);
    $this->postJson('/api/payments/webhooks/midtrans', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'payment.invalid_signature');
});

it('does not downgrade a paid payment when a stale pending notification arrives', function () {
    Event::fake([PaymentStatusChanged::class]);
    $payment = Payment::query()->create([
        'public_id' => fake()->uuid(), 'provider' => 'midtrans', 'provider_order_id' => 'CORE-notification-3',
        'reference_type' => 'billing.invoice', 'reference_id' => 'INV-4',
        'amount' => 90000, 'currency' => 'IDR', 'status' => PaymentStatus::Paid, 'paid_at' => now(),
    ]);

    $this->postJson('/api/payments/webhooks/midtrans', midtransPayload($payment, [
        'transaction_id' => 'stale-pending', 'transaction_status' => 'pending', 'status_code' => '201',
    ]))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
    Event::assertNotDispatched(PaymentStatusChanged::class);
});

it('rejects forged notifications and amount mismatches', function () {
    $payment = Payment::query()->create([
        'public_id' => fake()->uuid(), 'provider' => 'midtrans', 'provider_order_id' => 'CORE-notification-2',
        'reference_type' => 'billing.invoice', 'reference_id' => 'INV-2',
        'amount' => 50000, 'currency' => 'IDR', 'status' => PaymentStatus::Pending,
    ]);

    $forged = midtransPayload($payment);
    $forged['signature_key'] = str_repeat('0', 128);
    $this->postJson('/api/payments/webhooks/midtrans', $forged)
        ->assertUnauthorized()->assertJsonPath('code', 'payment.invalid_signature');

    $this->postJson('/api/payments/webhooks/midtrans', midtransPayload($payment, ['gross_amount' => '50001.00']))
        ->assertUnprocessable()->assertJsonPath('code', 'payment.invalid_notification');
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('exposes payment state only to its owner or authorized administrators', function () {
    $owner = User::factory()->create();
    $payment = Payment::query()->create([
        'public_id' => fake()->uuid(), 'user_id' => $owner->id,
        'provider' => 'midtrans', 'provider_order_id' => 'CORE-show-1',
        'reference_type' => 'billing.invoice', 'reference_id' => 'INV-3',
        'amount' => 75000, 'currency' => 'IDR', 'status' => PaymentStatus::Pending,
        'checkout_token' => 'must-not-leak', 'checkout_url' => 'https://example.test/checkout',
    ]);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/payments/'.$payment->public_id)->assertNotFound();

    Sanctum::actingAs($owner);
    $this->getJson('/api/payments/'.$payment->public_id)->assertOk()
        ->assertJsonPath('data.id', $payment->public_id)
        ->assertJsonMissingPath('data.checkout_token')
        ->assertJsonMissingPath('data.provider_order_id');
});
