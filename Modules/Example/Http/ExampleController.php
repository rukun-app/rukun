<?php

namespace Modules\Example\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ExampleController
{
    #[OA\Get(
        path: '/api/example',
        operationId: 'getExample',
        summary: 'Return the reference module response',
        tags: ['Example'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reference response',
                content: new OA\JsonContent(
                    required: ['success', 'data'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            required: ['module'],
                            properties: [new OA\Property(property: 'module', type: 'string', example: 'Example')],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(['module' => 'Example']);
    }
}
