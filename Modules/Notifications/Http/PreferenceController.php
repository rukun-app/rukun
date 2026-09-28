<?php

namespace Modules\Notifications\Http;

use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\NotificationRegistry;

class PreferenceController
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->effective($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['preferences' => ['required', 'array'], 'preferences.*.category' => ['required', Rule::in(array_keys(NotificationRegistry::CATEGORIES))], 'preferences.*.database_enabled' => ['required', 'boolean'], 'preferences.*.mail_enabled' => ['required', 'boolean']]);
        $before = $this->effective($request);

        foreach ($data['preferences'] as $preference) {
            $definition = NotificationRegistry::CATEGORIES[$preference['category']];
            foreach ($definition['locked'] as $channel) {
                if (! $preference[$channel.'_enabled']) {
                    throw ValidationException::withMessages(["preferences.{$preference['category']}.{$channel}_enabled" => [__('notifications.channel_required')]]);
                }
            }
            NotificationPreference::query()->updateOrCreate(['user_id' => $request->user()->id, 'category' => $preference['category']], ['database_enabled' => $preference['database_enabled'], 'mail_enabled' => $preference['mail_enabled']]);
        }

        Audit::record('notification.preferences_updated', metadata: ['before' => $before, 'after' => $this->effective($request)]);

        return ApiResponse::success($this->effective($request));
    }

    private function effective(Request $request): array
    {
        $stored = NotificationPreference::query()->whereBelongsTo($request->user(), 'user')->get()->keyBy('category');

        return collect(NotificationRegistry::CATEGORIES)->map(function (array $definition, string $category) use ($stored): array {
            $preference = $stored->get($category);

            return ['category' => $category, 'database_enabled' => $preference?->database_enabled ?? $definition['database'], 'mail_enabled' => $preference?->mail_enabled ?? $definition['mail'], 'locked_channels' => $definition['locked']];
        })->values()->all();
    }
}
