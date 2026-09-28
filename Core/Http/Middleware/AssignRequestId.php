<?php

namespace Core\Http\Middleware;

use Closure;
use Core\Support\CorrelationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function __construct(private CorrelationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Request-ID');
        $requestId = is_string($provided) && Str::isUuid($provided) ? $provided : (string) Str::uuid7();
        $this->context->set($requestId);
        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId, 'route' => $request->route()?->getName(), 'actor_id' => $request->user()?->getAuthIdentifier()]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } finally {
            $this->context->clear();
            Log::withoutContext();
        }
    }
}
