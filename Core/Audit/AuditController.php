<?php

namespace Core\Audit;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'event' => ['sometimes', 'string', 'max:150'],
            'actor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $request->integer('per_page', 20);

        $events = AuditEvent::query()
            ->when($request->string('event')->isNotEmpty(), fn ($query) => $query->where('event', $request->string('event')->toString()))
            ->when($request->integer('actor_id'), fn ($query, int $actorId) => $query->where('actor_id', $actorId))
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();

        return ApiResponse::success($events);
    }
}
