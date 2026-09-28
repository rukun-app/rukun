<?php

namespace Core\OpenApi;

use OpenApi\Builder;

class OpenApiDocument
{
    /** @var list<string> */
    private const RATE_LIMITED_OPERATIONS = [
        'register', 'login', 'resendVerification', 'forgotPassword', 'resetPassword',
        'uploadFile', 'pollEvents', 'readNotification', 'unreadNotification',
        'readAllNotifications', 'deleteNotification', 'updateNotificationPreferences',
        'createRole', 'updateRole', 'deleteRole', 'createUser', 'updateUserStatus',
        'syncUserRoles', 'updateSettings',
        'receiveMidtransNotification',
    ];

    public function json(): string
    {
        $result = (new Builder)
            ->setVersion('3.1.0')
            ->addSource(base_path('Core'))
            ->addSource(base_path('Modules'))
            ->build();
        $openApi = $result->openApi();

        if ($openApi === null) {
            throw new \RuntimeException('Unable to generate the OpenAPI document.');
        }

        $openApi->info->title = config('api-docs.title');
        $openApi->info->version = config('api-docs.version');

        $document = json_decode($openApi->toJson(), true, flags: JSON_THROW_ON_ERROR);

        foreach (['/api/files', '/api/users'] as $path) {
            $operation = &$document['paths'][$path]['post'];
            $operation['parameters'][] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => false,
                'description' => 'Unique key (8-128 characters) used to safely replay this request. Reusing it with a different payload returns 409.',
                'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$'],
                'example' => 'client-operation-0199a4e7',
            ];
            $operation['responses']['201']['headers']['Idempotency-Replayed'] = [
                'description' => 'false for the original execution and true when a cached response is replayed.',
                'schema' => ['type' => 'boolean'],
            ];
            $operation['responses']['409'] = [
                'description' => 'The key is currently processing or was already used with a different payload.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
            ];
        }

        foreach ($document['paths'] as &$pathItem) {
            foreach ($pathItem as &$operation) {
                if (! is_array($operation) || ! isset($operation['responses'])) {
                    continue;
                }

                if (in_array($operation['operationId'] ?? null, self::RATE_LIMITED_OPERATIONS, true)) {
                    $operation['responses']['429'] = $this->rateLimitResponse();
                }

                foreach ($operation['responses'] as &$response) {
                    $response['headers']['X-Request-ID'] = [
                        'description' => 'UUID correlation identifier. A valid client supplied value is echoed; otherwise the server generates one.',
                        'schema' => ['type' => 'string', 'format' => 'uuid'],
                        'example' => '0199a4e7-3f20-7b31-a592-1ed567ba84f1',
                    ];
                }
            }
        }

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function rateLimitResponse(): array
    {
        return [
            'description' => 'Rate limit exceeded. Retry after the number of seconds in Retry-After.',
            'headers' => [
                'Retry-After' => ['description' => 'Seconds until another request may be attempted.', 'schema' => ['type' => 'integer']],
                'X-RateLimit-Limit' => ['description' => 'Maximum requests in the current window.', 'schema' => ['type' => 'integer']],
                'X-RateLimit-Remaining' => ['description' => 'Requests remaining in the current window.', 'schema' => ['type' => 'integer']],
            ],
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
        ];
    }
}
