<?php

namespace Core\Http\Middleware;

use Closure;
use Core\Http\ApiResponse;
use Core\Support\Idempotency;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Modules\Settings\Settings;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function __construct(private Idempotency $idempotency, private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $next($request);
        }

        Validator::make(['key' => $key], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $scope = $this->idempotency->scope($request, $key);
        $fingerprint = $this->idempotency->fingerprint($request);
        $store = Cache::store('redis');
        $lock = $store->lock("idempotency:lock:{$scope}", 30);

        try {
            $lock->block(2);
        } catch (LockTimeoutException) {
            return ApiResponse::error(__('api.errors.idempotency_processing'), 409, [], 'idempotency.in_progress');
        }

        try {
            $cached = $store->get("idempotency:response:{$scope}");
            if ($cached !== null) {
                if (! hash_equals($cached['fingerprint'], $fingerprint)) {
                    return ApiResponse::error(__('api.errors.idempotency_conflict'), 409, [], 'idempotency.key_reused');
                }

                return response($cached['content'], $cached['status'], array_merge($cached['headers'], ['Idempotency-Replayed' => 'true']));
            }

            $response = $next($request);
            $response->headers->set('Idempotency-Replayed', 'false');
            if ($response->isSuccessful()) {
                $store->put("idempotency:response:{$scope}", [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content' => $response->getContent(),
                    'headers' => array_filter([
                        'Content-Type' => $response->headers->get('Content-Type', 'application/json'),
                        'Content-Language' => $response->headers->get('Content-Language'),
                    ]),
                ], now()->addHours((int) $this->settings->get('api.idempotency_ttl_hours')));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
