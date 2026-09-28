<?php

namespace Modules\Identity\Http;

use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->getKey();
        $tokens = $request->user()->tokens()->latest()->get()->map(fn (PersonalAccessToken $token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'created_at' => $token->created_at?->toISOString(),
            'is_current' => $token->getKey() === $currentId,
        ]);

        return ApiResponse::success($tokens);
    }

    public function destroy(Request $request, PersonalAccessToken $token): JsonResponse
    {
        abort_unless((int) $token->tokenable_id === (int) $request->user()->getKey() && $token->tokenable_type === $request->user()->getMorphClass(), 404, __('api.tokens.not_found'));
        Audit::record('auth.token_revoked', $request->user(), ['token_id' => $token->id, 'device_name' => $token->name]);
        $token->delete();

        return ApiResponse::success(['message' => __('api.tokens.revoked')]);
    }
}
