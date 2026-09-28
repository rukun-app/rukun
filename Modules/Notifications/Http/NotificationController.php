<?php

namespace Modules\Notifications\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:read,unread'], 'category' => ['sometimes', 'string', 'max:50'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $notifications = $request->user()->notifications()
            ->when(($data['status'] ?? null) === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when(($data['status'] ?? null) === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($data['category'] ?? null, fn ($query, string $category) => $query->where('data->category', $category))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($request->integer('per_page', 20))->withQueryString();
        $notifications->setCollection($notifications->getCollection()->map(fn (DatabaseNotification $notification) => $this->format($notification)));

        return ApiResponse::success($notifications);
    }

    public function count(Request $request): JsonResponse
    {
        return ApiResponse::success(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function show(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->owned($request, $notification);

        return ApiResponse::success($this->format($notification));
    }

    public function read(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->owned($request, $notification);
        $notification->markAsRead();

        return ApiResponse::success($this->format($notification->fresh()));
    }

    public function unread(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->owned($request, $notification);
        $notification->update(['read_at' => null]);

        return ApiResponse::success($this->format($notification->fresh()));
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['message' => __('notifications.read_all')]);
    }

    public function destroy(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->owned($request, $notification);
        $notification->delete();

        return ApiResponse::success(['message' => __('notifications.deleted')]);
    }

    private function owned(Request $request, DatabaseNotification $notification): void
    {
        abort_unless($notification->notifiable_type === $request->user()->getMorphClass() && (int) $notification->notifiable_id === (int) $request->user()->id, 404);
    }

    private function format(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return ['id' => $notification->id, 'category' => $data['category'], 'title' => __($data['title_key'], $data['parameters'] ?? []), 'message' => __($data['message_key'], $data['parameters'] ?? []), 'action' => $data['action'] ?? null, 'context' => $data['context'] ?? [], 'read_at' => $notification->read_at?->toISOString(), 'created_at' => $notification->created_at?->toISOString()];
    }
}
