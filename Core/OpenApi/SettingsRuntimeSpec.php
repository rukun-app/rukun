<?php

namespace Core\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Delete(path: '/api/settings/{key}', operationId: 'resetSetting', summary: 'Remove a database override and restore its environment or code fallback', security: [['bearerAuth' => []]], tags: ['Settings'], parameters: [new OA\Parameter(name: 'key', description: 'Registered setting key, including its group prefix', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'rate_limit.auth_login'))], responses: [new OA\Response(response: 200, description: 'Override removed and effective fallback returned', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', required: ['key', 'value', 'source'], properties: [new OA\Property(property: 'key', type: 'string', example: 'rate_limit.auth_login'), new OA\Property(property: 'value', type: 'integer', example: 20), new OA\Property(property: 'source', type: 'string', enum: ['environment', 'default'], example: 'environment')], type: 'object')], type: 'object')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')), new OA\Response(response: 403, description: 'Missing settings.update permission', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')), new OA\Response(response: 404, description: 'Setting key is not registered', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))])]
final class SettingsRuntimeSpec {}
