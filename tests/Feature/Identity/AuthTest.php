<?php

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Settings\Models\Setting;
use Modules\Settings\SettingsRegistry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    foreach (array_keys(SettingsRegistry::DEFINITIONS) as $key) {
        cache()->forget('setting:'.$key);
    }
});

it('issues a bearer token to a verified active user', function () {
    $user = User::factory()->create(['password' => Hash::make('a-secure-password')]);

    $this->postJson('/api/auth/login', ['email' => strtoupper($user->email), 'password' => 'a-secure-password', 'device_name' => 'test'])
        ->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.id', $user->id);

    expect($user->tokens()->count())->toBe(1)->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects invalid credentials, suspended users, and unverified users', function () {
    $active = User::factory()->create();
    $this->postJson('/api/auth/login', ['email' => $active->email, 'password' => 'wrong-password', 'device_name' => 'test'])->assertStatus(422);

    $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $this->postJson('/api/auth/login', ['email' => $suspended->email, 'password' => 'password', 'device_name' => 'test'])->assertForbidden();

    $unverified = User::factory()->unverified()->create();
    $this->postJson('/api/auth/login', ['email' => $unverified->email, 'password' => 'password', 'device_name' => 'test'])->assertForbidden();
});

it('returns the current profile and revokes all tokens', function () {
    $user = User::factory()->create();
    $user->createToken('one');
    Sanctum::actingAs($user);

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    $this->postJson('/api/auth/logout-all')->assertOk();
    expect($user->tokens()->count())->toBe(0);
});

it('lists and revokes only tokens owned by the current user', function () {
    $user = User::factory()->create();
    $current = $user->createToken('current')->plainTextToken;
    $other = $user->createToken('tablet')->accessToken;
    $foreign = User::factory()->create()->createToken('foreign')->accessToken;

    $this->withToken($current)->getJson('/api/auth/tokens')->assertOk()
        ->assertJsonFragment(['name' => 'current', 'is_current' => true])
        ->assertJsonFragment(['name' => 'tablet', 'is_current' => false]);

    $this->withToken($current)->deleteJson("/api/auth/tokens/{$foreign->id}")->assertNotFound();
    $this->withToken($current)->deleteJson("/api/auth/tokens/{$other->id}")->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['current']);
});

it('updates the basic profile and changes password', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current')->plainTextToken;
    $user->createToken('other');
    $client = $this->withToken($currentToken);

    $client->patchJson('/api/auth/profile', ['name' => 'Updated Name'])->assertOk()->assertJsonPath('data.name', 'Updated Name');
    $client->putJson('/api/auth/password', ['current_password' => 'password', 'password' => 'updated-password', 'password_confirmation' => 'updated-password'])->assertOk();

    expect(Hash::check('updated-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);
});

it('keeps registration disabled by default and allows it through settings', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    $payload = ['name' => 'New User', 'email' => 'NEW@example.test', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'];
    $this->postJson('/api/auth/register', $payload)->assertForbidden();

    Setting::query()->create(['key' => 'auth.registration_enabled', 'value' => true]);
    cache()->forget('setting:auth.registration_enabled');
    $this->postJson('/api/auth/register', $payload)->assertCreated();

    expect(User::query()->where('email', 'new@example.test')->first()->hasRole('user'))->toBeTrue();
});

it('verifies email using a signed URL', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->getJson($url)->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('resets a password and revokes existing tokens', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $user = User::factory()->create();
    $user->createToken('existing');
    $token = Password::createToken($user);

    $this->postJson('/api/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertOk();

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});
