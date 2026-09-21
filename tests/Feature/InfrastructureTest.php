<?php

use Core\Jobs\ProbeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('uses the isolated PostgreSQL test database', function () {
    expect(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.database'))->toBe('laravel_core_test');

    expect(DB::select('SELECT 1 AS connected')[0]->connected)->toBe(1);
})->uses(RefreshDatabase::class);

it('stores cache values in the isolated Redis database', function () {
    expect(config('database.redis.cache.database'))->toBe('13')
        ->and(config('database.redis.default.database'))->toBe('14')
        ->and(config('database.redis.session.database'))->toBe('15');

    Cache::forget('phase-one-probe');
    expect(Cache::remember('phase-one-probe', 60, fn () => 'redis-ok'))->toBe('redis-ok');
    Cache::forget('phase-one-probe');
});

it('dispatches a job', function () {
    Queue::fake();
    ProbeJob::dispatch('fake-probe');
    Queue::assertPushed(ProbeJob::class);
});

it('processes a job with the Redis queue', function () {
    $key = 'worker-probe-'.uniqid();
    ProbeJob::dispatch($key)->onQueue('low');
    Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => 'low', '--stop-when-empty' => true]);
    expect(Cache::get($key))->toBe('processed');
    Cache::forget($key);
});

it('registers and executes the scheduler command', function () {
    expect(Artisan::call('core:heartbeat'))->toBe(0);
    expect(Artisan::output())->toContain('Core scheduler is running.');
    expect(Artisan::call('schedule:list'))->toBe(0);
    expect(Artisan::output())->toContain('core:heartbeat');
});
