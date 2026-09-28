<?php

namespace Core\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Core\Http\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== UserStatus::Active) {
            $request->user()?->tokens()->delete();

            return ApiResponse::error(__('api.auth.suspended'), 403, code: 'auth.suspended');
        }

        return $next($request);
    }
}
