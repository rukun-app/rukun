<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('serves Swagger UI and a valid OpenAPI document', function () {
    $this->get('/docs/api')->assertOk()->assertSee('swagger-ui');

    $document = $this->getJson('/docs/api/openapi.json')->assertOk()->json();

    expect($document['openapi'])->toStartWith('3.1.')
        ->and($document['paths'])->toHaveKeys(['/api/health', '/api/example'])
        ->and($document['components']['schemas'])->toHaveKeys(['ApiError', 'ServiceHealth']);
});

it('can disable public API documentation', function () {
    config()->set('api-docs.enabled', false);

    $this->get('/docs/api')->assertNotFound();
    $this->get('/docs/api/openapi.json')->assertNotFound();
});

it('generates a static OpenAPI document', function () {
    $path = 'storage/framework/testing/openapi.json';

    try {
        expect(Artisan::call('api-docs:generate', ['--output' => $path]))->toBe(0);
        expect(json_decode(File::get(base_path($path)), true, flags: JSON_THROW_ON_ERROR))
            ->toHaveKey('paths./api/health');
    } finally {
        File::delete(base_path($path));
    }
});

it('serves only approved local Swagger UI assets', function () {
    $this->get('/docs/api/assets/swagger-ui.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
    $this->get('/docs/api/assets/composer.json')->assertNotFound();
});
