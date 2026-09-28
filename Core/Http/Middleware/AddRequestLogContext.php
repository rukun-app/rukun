<?php

namespace Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AddRequestLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::withContext([
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'module' => $this->module($request),
        ]);

        return $next($request);
    }

    private function module(Request $request): string
    {
        $action = $request->route()?->getActionName() ?? '';

        return str_starts_with($action, 'Modules\\') ? explode('\\', $action)[1] : 'Core';
    }
}
