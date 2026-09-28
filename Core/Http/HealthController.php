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
                content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse'),
            ),
            new OA\Response(response: 503, description: 'A required service is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse')),
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
