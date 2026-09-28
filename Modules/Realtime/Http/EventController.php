<?php

namespace Modules\Realtime\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Realtime\Models\UserEvent;
use Modules\Realtime\Support\EventCursor;

class EventController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'string'],
            'types' => ['sometimes', 'array', 'max:20'],
            'types.*' => ['string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $cursor = EventCursor::decode($data['cursor'] ?? null);
        $cursorId = $cursor['id'] ?? null;
        $base = UserEvent::query()->where('user_id', $request->user()->getKey());

        if (($cursor['expires_at'] ?? null) && now()->greaterThanOrEqualTo($cursor['expires_at'])) {
            return ApiResponse::error(__('api.errors.cursor_expired'), 409, [], 'realtime.cursor_expired');
        }

        if ($cursorId && ! (clone $base)->whereKey($cursorId)->exists()) {
            $oldest = (clone $base)->oldest('id')->value('id');
            if ($oldest !== null && $cursorId < $oldest) {
                return ApiResponse::error(__('api.errors.cursor_expired'), 409, [], 'realtime.cursor_expired');
            }
        }

        $events = $base
            ->when($cursorId, fn ($query) => $query->where('id', '>', $cursorId))
            ->when($data['types'] ?? null, fn ($query, array $types) => $query->whereIn('type', $types))
            ->oldest('id')
            ->limit($data['limit'] ?? 50)
            ->get();

        return ApiResponse::success([
            'events' => $events->map->envelope()->values(),
            'next_cursor' => $events->isNotEmpty()
                ? EventCursor::encode($events->last()->id, $events->last()->expires_at)
                : ($data['cursor'] ?? null),
        ]);
    }

    public function cursor(Request $request): JsonResponse
    {
        $event = UserEvent::query()->where('user_id', $request->user()->getKey())->latest('id')->first();

        return ApiResponse::success(['cursor' => EventCursor::encode($event?->id, $event?->expires_at)]);
    }
}
