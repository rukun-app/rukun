<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    expect($data)->toHaveKey('app.name', 'Core R')
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

    $this->getJson('/api/settings/metadata')->assertOk()
        ->assertJsonFragment([
            'key' => 'auth.registration_enabled',
            'type' => 'boolean',
            'editable' => true,
            'description' => 'Allow public user registration.',
        ]);
});

it('rejects unsupported file upload mime settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $this->patchJson('/api/settings', ['settings' => ['files.allowed_mime_types' => ['text/html']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('settings.files.allowed_mime_types');
});
