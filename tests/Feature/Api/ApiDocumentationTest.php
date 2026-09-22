<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('serves Swagger UI and a valid OpenAPI document', function () {
    $this->get('/docs/api')->assertOk()->assertSee('swagger-ui');

    $document = $this->getJson('/docs/api/openapi.json')->assertOk()->json();

    expect($document['openapi'])->toStartWith('3.1.')
        ->and($document['paths'])->toHaveKeys([
            '/api/health', '/api/example', '/api/auth/register', '/api/auth/login', '/api/auth/me', '/api/auth/profile', '/api/auth/password',
            '/api/auth/logout', '/api/auth/logout-all', '/api/auth/email/resend', '/api/auth/email/verify/{id}/{hash}',
            '/api/auth/forgot-password', '/api/auth/reset-password', '/api/permissions', '/api/roles', '/api/roles/{role}',
            '/api/auth/tokens', '/api/auth/tokens/{token}', '/api/users', '/api/users/{user}', '/api/users/{user}/status',
            '/api/users/{user}/roles', '/api/settings/public', '/api/settings', '/api/settings/metadata', '/api/audit-events',
        ])
        ->and($document['paths']['/api/auth/login']['post'])->toHaveKey('requestBody')
        ->and($document['paths']['/api/auth/login']['post']['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/LoginSuccessResponse')
        ->and($document['paths']['/api/auth/login']['post']['responses']['422']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ValidationErrorResponse')
        ->and($document['paths']['/api/settings']['patch'])->toHaveKey('requestBody')
        ->and($document['components']['schemas'])->toHaveKeys(['ApiError', 'ServiceHealth', 'LoginSuccessResponse', 'ErrorResponse', 'ValidationErrorResponse']);
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
