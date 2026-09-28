<?php

namespace Core\OpenApi;

use OpenApi\Attributes as OA;

#[OA\OpenApi(openapi: '3.1.0')]
#[OA\Info(
    version: '1.0.0',
    title: 'Core R API',
    description: 'Standard API contract for Core R.',
)]
#[OA\Server(url: '/', description: 'Current environment')]
#[OA\Tag(name: 'System', description: 'Application infrastructure and readiness')]
#[OA\Tag(name: 'Example', description: 'Reference module endpoints')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'Sanctum bearer token. Apply this scheme to protected endpoints.',
    bearerFormat: 'token',
    scheme: 'bearer',
)]
#[OA\Schema(
    schema: 'ServiceHealth',
    required: ['status', 'required'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['up', 'down', 'not_configured']),
        new OA\Property(property: 'required', type: 'boolean'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ApiError',
    required: ['success', 'code', 'message', 'errors'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'code', type: 'string', example: 'resource.not_found'),
        new OA\Property(property: 'message', type: 'string', example: 'Request failed'),
        new OA\Property(property: 'errors', type: 'object', example: []),
    ],
    type: 'object',
)]
final class OpenApiSpec {}
