<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Settings\Settings;
use Modules\Settings\SettingsRegistry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    foreach (array_keys(SettingsRegistry::DEFINITIONS) as $key) {
        cache()->forget('setting:'.$key);
    }
});

it('exposes only public settings anonymously', function () {
    $data = $this->getJson('/api/settings/public')->assertOk()->json('data');

    expect($data)->toHaveKey('app.name', config('runtime-settings.app_name') ?? 'Core R')
        ->not->toHaveKey('auth.token_expiration_days');
});

it('validates, stores, caches, and audits setting changes', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $this->patchJson('/api/settings', ['settings' => ['app.name' => 'My Platform', 'auth.token_expiration_days' => 14]])
        ->assertOk()->assertJsonFragment(['app.name' => 'My Platform']);

    $this->assertDatabaseHas('settings', ['key' => 'app.name', 'updated_by' => $admin->id]);
    $this->assertDatabaseHas('audit_events', ['event' => 'settings.updated', 'actor_id' => $admin->id]);
});

it('exposes settings metadata to authorized users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/settings/metadata')->assertOk()
        ->assertJsonFragment([
            'key' => 'auth.registration_enabled',
            'type' => 'boolean',
            'group' => 'authentication',
            'editable' => true,
            'description' => 'Allow public user registration.',
        ]);

    $registration = collect($response->json('data'))->firstWhere('key', 'auth.registration_enabled');
    expect($registration)->toHaveKeys(['group', 'type', 'value', 'source', 'fallback_value', 'default', 'rules'])
        ->not->toHaveKey('fallback');
});

it('resolves database then environment then code defaults and exposes the source', function () {
    config()->set('runtime-settings.max_login_attempts', 9);
    cache()->forget('setting:auth.max_login_attempts');
    $settings = app(Settings::class);

    expect($settings->get('auth.max_login_attempts'))->toBe(9)
        ->and($settings->source('auth.max_login_attempts'))->toBe('environment');

    $settings->put('auth.max_login_attempts', 7, null);
    expect($settings->get('auth.max_login_attempts'))->toBe(7)
        ->and($settings->source('auth.max_login_attempts'))->toBe('database');
    $this->assertDatabaseHas('settings', [
        'key' => 'auth.max_login_attempts', 'type' => 'integer', 'group' => 'authentication',
    ]);

    $settings->forget('auth.max_login_attempts');
    expect($settings->get('auth.max_login_attempts'))->toBe(9)
        ->and($settings->source('auth.max_login_attempts'))->toBe('environment');

    config()->set('runtime-settings.max_login_attempts', null);
    cache()->forget('setting:auth.max_login_attempts');
    expect($settings->get('auth.max_login_attempts'))->toBe(5)
        ->and($settings->source('auth.max_login_attempts'))->toBe('default');
});

it('resets a database override through the API', function () {
    config()->set('runtime-settings.rate_limits.auth_login', 21);
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $this->patchJson('/api/settings', ['settings' => ['rate_limit.auth_login' => 12]])->assertOk();
    $this->deleteJson('/api/settings/rate_limit.auth_login')->assertOk()
        ->assertJsonPath('data.value', 21)
        ->assertJsonPath('data.source', 'environment');

    $this->assertDatabaseMissing('settings', ['key' => 'rate_limit.auth_login']);
    $this->assertDatabaseHas('audit_events', ['event' => 'settings.reset', 'actor_id' => $admin->id]);
});

it('rejects unsupported file upload mime settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $this->patchJson('/api/settings', ['settings' => ['files.allowed_mime_types' => ['text/html']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('settings.files.allowed_mime_types');
});
