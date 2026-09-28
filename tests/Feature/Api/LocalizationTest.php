<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

it('publishes supported locales and language headers', function () {
    $this->getJson('/api/locales', ['Accept-Language' => 'id-ID,id;q=0.9'])
        ->assertOk()
        ->assertHeader('Content-Language', 'id')
        ->assertJsonPath('data.supported.0.code', 'en')
        ->assertJsonPath('data.supported.1.code', 'id')
        ->assertJsonPath('data.fallback', 'en');
});

it('localizes standard errors and keeps a stable machine code', function () {
    $indonesian = $this->getJson('/api/auth/me', ['Accept-Language' => 'id-ID']);
    $english = $this->getJson('/api/auth/me', ['Accept-Language' => 'en-US']);

    $indonesian->assertUnauthorized()->assertHeader('Content-Language', 'id')
        ->assertJsonPath('code', 'auth.unauthenticated')
        ->assertJsonPath('message', 'Anda belum terautentikasi.');
    $english->assertUnauthorized()->assertHeader('Content-Language', 'en')
        ->assertJsonPath('code', 'auth.unauthenticated')
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('localizes validation errors and falls back from unsupported languages', function () {
    $this->postJson('/api/auth/login', [], ['Accept-Language' => 'id'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation.failed')
        ->assertJsonPath('message', 'Validasi gagal.')
        ->assertJsonPath('errors.email.0', 'email wajib diisi.');

    $this->getJson('/api/auth/me', ['Accept-Language' => 'fr-FR'])
        ->assertUnauthorized()->assertHeader('Content-Language', 'en');
});

it('uses the user preference and allows a request override', function () {
    $user = User::factory()->create(['locale' => 'id']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/auth/logout', [], ['Accept-Language' => ''])->assertOk()
        ->assertHeader('Content-Language', 'id')
        ->assertJsonPath('data.message', 'Berhasil keluar.');

    $overrideToken = $user->createToken('override')->plainTextToken;
    $this->withToken($overrideToken)->postJson('/api/auth/logout', [], ['Accept-Language' => 'en-US'])->assertOk()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('data.message', 'Logged out.');
});

it('updates and returns the locale in the user profile', function () {
    $user = User::factory()->create();
    $token = $user->createToken('profile')->plainTextToken;

    $this->withToken($token)->patchJson('/api/auth/profile', ['name' => $user->name, 'locale' => 'id'])
        ->assertOk()->assertJsonPath('data.locale', 'id');
    expect($user->fresh()->preferredLocale())->toBe('id');
});

it('keeps core API translation keys in parity', function () {
    $flatten = fn (array $messages): array => array_keys(collect($messages)->dot()->all());

    expect($flatten(require lang_path('en/api.php')))->toBe($flatten(require lang_path('id/api.php')));
});
