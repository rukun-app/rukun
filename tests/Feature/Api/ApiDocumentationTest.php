<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('serves Swagger UI and a valid OpenAPI document', function () {
    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('swagger-ui')
        ->assertSee('href="/docs/api/assets/swagger-ui.css"', false)
        ->assertSee('src="/docs/api/assets/swagger-ui-bundle.js"', false)
        ->assertSee('url: "\/docs\/api\/openapi.json"', false);

    $document = $this->getJson('/docs/api/openapi.json')->assertOk()->json();

    expect($document['openapi'])->toStartWith('3.1.')
        ->and($document['paths'])->toHaveKeys([
            '/api/health', '/api/locales', '/api/example', '/api/auth/register', '/api/auth/login', '/api/auth/me', '/api/auth/profile', '/api/auth/password',
            '/api/auth/logout', '/api/auth/logout-all', '/api/auth/email/resend', '/api/auth/email/verify/{id}/{hash}',
            '/api/auth/forgot-password', '/api/auth/reset-password', '/api/permissions', '/api/roles', '/api/roles/{role}',
            '/api/auth/tokens', '/api/auth/tokens/{token}', '/api/users', '/api/users/{user}', '/api/users/{user}/status',
            '/api/users/{user}/roles', '/api/settings/public', '/api/settings', '/api/settings/metadata', '/api/audit-events',
            '/api/files', '/api/files/{file}', '/api/files/{file}/download',
            '/api/notifications', '/api/notifications/unread-count', '/api/notifications/{notification}',
            '/api/notifications/{notification}/read', '/api/notifications/read-all', '/api/notification-preferences',
            '/api/payments', '/api/payments/{payment}', '/api/payments/webhooks/midtrans',
            '/api/data-transfers', '/api/data-transfers/types', '/api/data-transfers/exports', '/api/data-transfers/imports',
            '/api/data-transfers/{dataTransfer}', '/api/data-transfers/{dataTransfer}/cancel',
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

it('documents a response body schema for every response before execution', function () {
    $document = $this->getJson('/docs/api/openapi.json')->assertOk()->json();
    $missing = [];

    foreach ($document['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (! is_array($operation) || ! isset($operation['responses'])) {
                continue;
            }

            foreach ($operation['responses'] as $status => $response) {
                foreach ($response['content'] ?? [] as $mediaType => $media) {
                    if (! isset($media['schema']) && ! isset($media['example']) && ! isset($media['examples'])) {
                        $missing[] = strtoupper($method).' '.$path.' '.$status.' '.$mediaType;
                    }
                }

                if (($response['content'] ?? []) === []) {
                    $missing[] = strtoupper($method).' '.$path.' '.$status.' no-content';
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

it('documents idempotency and named rate limit contracts', function () {
    $document = $this->getJson('/docs/api/openapi.json')->assertOk()->json();

    foreach (['/api/files', '/api/users'] as $path) {
        $operation = $document['paths'][$path]['post'];
        $parameter = collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key');

        expect($parameter)->not->toBeNull()
            ->and($parameter['in'])->toBe('header')
            ->and($operation['responses']['201']['headers'])->toHaveKey('Idempotency-Replayed')
            ->and($operation['responses']['409']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ErrorResponse');
    }

    $paymentOperation = $document['paths']['/api/payments']['post'];
    $paymentIdempotency = collect($paymentOperation['parameters'])->firstWhere('name', 'Idempotency-Key');
    expect($paymentIdempotency['required'])->toBeTrue()
        ->and($paymentOperation['responses']['201']['headers'])->toHaveKey('Idempotency-Replayed')
        ->and($paymentOperation['responses'])->toHaveKeys(['409', '429']);

    foreach (['/api/data-transfers/exports', '/api/data-transfers/imports'] as $path) {
        $operation = $document['paths'][$path]['post'];
        $parameter = collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key');
        expect($parameter['required'])->toBeTrue()
            ->and($operation['responses']['202']['headers'])->toHaveKey('Idempotency-Replayed')
            ->and($operation['responses'])->toHaveKeys(['409', '429']);
    }

    foreach ([['/api/auth/login', 'post'], ['/api/events', 'get'], ['/api/settings', 'patch']] as [$path, $method]) {
        $response = $document['paths'][$path][$method]['responses']['429'];

        expect($response['headers'])->toHaveKeys(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-Request-ID'])
            ->and($response['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ErrorResponse');
    }
});
