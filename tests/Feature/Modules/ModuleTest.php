<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Modules\Example\Providers\ExampleServiceProvider;

it('loads the example module provider', function () {
    expect(app()->getProvider(ExampleServiceProvider::class))->not->toBeNull();
});

it('creates a module structure', function () {
    $name = 'GeneratedProbe';
    $path = base_path('Modules/'.$name);

    try {
        expect(Artisan::call('make:module', ['name' => $name]))->toBe(0);
        expect(File::exists($path.'/Providers/'.$name.'ServiceProvider.php'))->toBeTrue()
            ->and(File::exists($path.'/routes/api.php'))->toBeTrue();
    } finally {
        File::deleteDirectory($path);
    }
});
