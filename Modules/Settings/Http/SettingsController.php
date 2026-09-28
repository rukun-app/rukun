<?php

namespace Modules\Settings\Http;

use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Settings\Settings;
use Modules\Settings\SettingsRegistry;

class SettingsController
{
    public function public(Settings $settings): JsonResponse
    {
        return ApiResponse::success($settings->all(publicOnly: true));
    }

    public function index(Settings $settings): JsonResponse
    {
        return ApiResponse::success($settings->all());
    }

    public function metadata(Settings $settings): JsonResponse
    {
        return ApiResponse::success(collect(SettingsRegistry::DEFINITIONS)->map(
            fn (array $definition, string $key): array => [
                'key' => $key,
                'value' => $settings->get($key),
                'source' => $settings->source($key),
                ...Arr::except($definition, ['fallback']),
                'fallback_value' => config($definition['fallback']) ?? $definition['default'],
                'description' => __($definition['description']),
            ]
        )->values());
    }

    public function update(Request $request, Settings $settings): JsonResponse
    {
        $values = $request->validate(['settings' => ['required', 'array']])['settings'];
        $unknown = array_diff(array_keys($values), array_keys(SettingsRegistry::DEFINITIONS));
        abort_if($unknown !== [], 422, __('api.settings.unknown', ['keys' => implode(', ', $unknown)]));

        $validated = collect($values)->mapWithKeys(function (mixed $value, string $key): array {
            $validatedValue = Validator::make(['value' => $value], ['value' => SettingsRegistry::DEFINITIONS[$key]['rules']])->validate()['value'];

            if ($key === 'files.allowed_mime_types' && array_diff($validatedValue, config('files.allowed_mime_types')) !== []) {
                throw ValidationException::withMessages(['settings.files.allowed_mime_types' => ['One or more MIME types are not supported.']]);
            }

            if ($key === 'app.locale' && ! in_array($validatedValue, config('localization.supported'), true)) {
                throw ValidationException::withMessages(['settings.app.locale' => ['The selected locale is not supported.']]);
            }

            return [$key => $validatedValue];
        })->all();
        $before = collect(array_keys($validated))->mapWithKeys(fn (string $key) => [$key => $settings->get($key)])->all();

        foreach ($validated as $key => $value) {
            $settings->put($key, $value, $request->user()->id);
        }

        Audit::record('settings.updated', metadata: ['before' => $before, 'after' => $validated]);

        return ApiResponse::success($settings->all());
    }

    public function reset(Request $request, string $key, Settings $settings): JsonResponse
    {
        abort_unless(array_key_exists($key, SettingsRegistry::DEFINITIONS), 404);
        $before = $settings->get($key);
        $settings->forget($key);
        Audit::record('settings.reset', metadata: ['key' => $key, 'before' => $before, 'after' => $settings->get($key)]);

        return ApiResponse::success([
            'key' => $key,
            'value' => $settings->get($key),
            'source' => $settings->source($key),
        ]);
    }
}
