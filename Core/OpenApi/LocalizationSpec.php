<?php

namespace Core\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Localization', description: 'Supported API response languages')]
#[OA\Get(path: '/api/locales', operationId: 'listLocales', summary: 'List supported, default, and fallback locales', tags: ['Localization'], parameters: [new OA\Parameter(name: 'Accept-Language', description: 'Preferred language. Regional tags such as id-ID and en-US are supported.', in: 'header', schema: new OA\Schema(type: 'string', example: 'id-ID,id;q=0.9'))], responses: [new OA\Response(response: 200, description: 'Locale configuration', headers: [new OA\Header(header: 'Content-Language', description: 'Locale used to render this response', schema: new OA\Schema(type: 'string', enum: ['en', 'id']))], content: new OA\JsonContent(ref: '#/components/schemas/LocalesResponse'))])]
final class LocalizationSpec {}
