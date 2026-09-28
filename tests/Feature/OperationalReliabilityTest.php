<?php

use App\Models\User;
use Core\Audit\Audit;
use Core\Audit\AuditEvent;
use Core\Jobs\ProbeJob;
use Core\Logging\AddSensitiveDataRedaction;
use Core\Logging\RedactSensitiveContext;
use Core\Support\CorrelationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Notifications\Notifications\CoreNotification;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

it('returns a valid request id and echoes a valid client id', function () {
    $generated = $this->getJson('/api/health')->assertOk()->headers->get('X-Request-ID');
    expect(Str::isUuid($generated))->toBeTrue();

    $provided = (string) Str::uuid();
    $this->withHeader('X-Request-ID', $provided)->getJson('/api/health')
        ->assertOk()->assertHeader('X-Request-ID', $provided);

    $invalid = $this->withHeader('X-Request-ID', 'not-a-uuid')->getJson('/api/health')->assertOk()->headers->get('X-Request-ID');
    expect(Str::isUuid($invalid))->toBeTrue()->and($invalid)->not->toBe('not-a-uuid');
});

it('propagates correlation to queue payload audit and notification data', function () {
    $requestId = (string) Str::uuid();
    app(CorrelationContext::class)->set($requestId);
    $connection = Queue::connection('redis');
    $method = new ReflectionMethod($connection, 'createPayload');
    $payload = json_decode($method->invoke($connection, new ProbeJob('correlation-test'), 'low'), true, flags: JSON_THROW_ON_ERROR);

    $user = User::factory()->create();
    $this->actingAs($user);
    Audit::record('test.correlated', $user);
    $notification = new CoreNotification('account', 'notifications.test_title', 'notifications.test_message');

    expect($payload['request_id'])->toBe($requestId)
        ->and($notification->toArray($user)['context']['request_id'])->toBe($requestId)
        ->and(AuditEvent::query()->where('event', 'test.correlated')->firstOrFail()->metadata['request_id'])->toBe($requestId);
});

it('redacts nested sensitive logging context', function () {
    $record = new LogRecord(now()->toDateTimeImmutable(), 'test', Level::Info, 'probe', ['email' => 'admin@example.com', 'password' => 'secret', 'nested' => ['authorization' => 'Bearer token']]);
    $redacted = (new RedactSensitiveContext)($record);

    expect($redacted->context['email'])->toBe('admin@example.com')
        ->and($redacted->context['password'])->toBe('[REDACTED]')
        ->and($redacted->context['nested']['authorization'])->toBe('[REDACTED]');
});

it('enables sensitive context redaction on human readable and structured logs', function () {
    expect(config('logging.channels.single.tap'))->toContain(AddSensitiveDataRedaction::class)
        ->and(config('logging.channels.daily.tap'))->toContain(AddSensitiveDataRedaction::class)
        ->and(config('logging.channels.json.processors'))->toContain(RedactSensitiveContext::class);
});

it('provides safe failed job summary and scheduled pruning', function () {
    $this->artisan('queue:failed-summary')->assertSuccessful();
    $this->artisan('core:prune-failed-jobs')->assertSuccessful();
    $this->artisan('schedule:list')->expectsOutputToContain('core:prune-failed-jobs')->assertSuccessful();
});
