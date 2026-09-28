<?php

namespace Core\Http;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status, array $errors = [], string $code = 'request.failed'): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message, 'errors' => $errors], $status);
    }
}
