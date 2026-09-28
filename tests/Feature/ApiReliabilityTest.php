<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->seed(RbacSeeder::class);
});

it('replays a successful idempotent user creation without duplicate side effects', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('users.create');
    Sanctum::actingAs($admin);
    $key = 'create-user-'.uniqid();
    $payload = ['name' => 'Idempotent User', 'email' => 'idem@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password', 'roles' => ['user']];

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/users', $payload)
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/users', $payload)
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(User::query()->where('email', 'idem@example.com')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event', 'user.created')->count())->toBe(1);
});

it('rejects idempotency key reuse with a different fingerprint', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('users.create');
    Sanctum::actingAs($admin);
    $key = 'fingerprint-'.uniqid();
    $payload = ['name' => 'First User', 'email' => 'first@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'];

    $this->withHeader('Idempotency-Key', $key)->postJson('/api/users', $payload)->assertCreated();
    $payload['name'] = 'Changed User';

    $this->withHeader('Idempotency-Key', $key)->postJson('/api/users', $payload)
        ->assertStatus(409)->assertJsonPath('code', 'idempotency.key_reused');
});

it('rejects a concurrent request while the idempotency key is locked', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('users.create');
    Sanctum::actingAs($admin);
    $key = 'concurrent-'.uniqid();
    $scope = hash('sha256', $admin->id.'|POST|api/users|'.$key);
    $lock = Cache::store('redis')->lock("idempotency:lock:{$scope}", 10);
    expect($lock->get())->toBeTrue();

    try {
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/users', [
            'name' => 'Concurrent User', 'email' => 'concurrent@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password',
        ])->assertStatus(409)->assertJsonPath('code', 'idempotency.in_progress');
    } finally {
        $lock->release();
    }
});

it('replays an identical file upload without storing a duplicate', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $key = 'upload-file-'.uniqid();
    $payload = fn () => [
        'file' => UploadedFile::fake()->createWithContent('report.txt', 'same-content'),
        'display_name' => 'Report.txt',
    ];

    $first = $this->withHeader('Idempotency-Key', $key)->post('/api/files', $payload(), ['Accept' => 'application/json'])
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
    $second = $this->withHeader('Idempotency-Key', $key)->post('/api/files', $payload(), ['Accept' => 'application/json'])
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(DB::table('files')->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('uses a named Redis rate limiter and returns retry headers', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    RateLimiter::for('events-poll', fn () => Limit::perMinute(1)->by('events-test:'.$user->id));
    RateLimiter::clear('events-test:'.$user->id);

    $this->getJson('/api/events')->assertOk();
    $this->getJson('/api/events')->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', 1)
        ->assertJsonPath('code', 'request.rate_limited');
});
