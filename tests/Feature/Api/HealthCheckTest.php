<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('reports required services as healthy', function () {
    config()->set('filesystems.default', 'local');

    $this->getJson('/api/health')->assertOk()
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('services.database.status', 'up')
        ->assertJsonPath('services.redis.status', 'up')
        ->assertJsonPath('services.storage.required', false);
});

it('reports a failed required service', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('Unavailable'));
    config()->set('filesystems.default', 'local');

    $this->getJson('/api/health')->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('services.database.status', 'down');
});

it('does not fail when optional storage is down', function () {
    config()->set('filesystems.default', 's3');
    Storage::shouldReceive('disk')->with('s3')->andThrow(new RuntimeException('Unavailable'));

    $this->getJson('/api/health')->assertOk()
        ->assertJsonPath('services.storage.status', 'down');
});
