<?php

namespace Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Throwable;

class HealthController
{
    #[OA\Get(
        path: '/api/health',
        operationId: 'getHealth',
        summary: 'Check required and optional services',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All required services are available',
                content: new OA\JsonContent(
                    required: ['status', 'services'],
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['healthy'], example: 'healthy'),
                        new OA\Property(property: 'services', type: 'object', additionalProperties: new OA\AdditionalProperties(ref: '#/components/schemas/ServiceHealth')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 503, description: 'A required service is unavailable'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $services = [
            'database' => $this->check(fn () => DB::select('SELECT 1'), true),
            'redis' => $this->check(fn () => Redis::connection('cache')->ping(), true),
            'storage' => config('filesystems.default') === 's3'
                ? $this->check(fn () => Storage::disk('s3')->files(''), false)
                : ['status' => 'not_configured', 'required' => false],
            'ai' => ['status' => 'not_configured', 'required' => false],
        ];
        $healthy = $services['database']['status'] === 'up' && $services['redis']['status'] === 'up';

        return response()->json(['status' => $healthy ? 'healthy' : 'unhealthy', 'services' => $services], $healthy ? 200 : 503);
    }

    private function check(callable $probe, bool $required): array
    {
        try {
            $probe();

            return ['status' => 'up', 'required' => $required];
        } catch (Throwable) {
            return ['status' => 'down', 'required' => $required];
        }
    }
}
