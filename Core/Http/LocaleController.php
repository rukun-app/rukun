<?php

namespace Core\Http;

use Illuminate\Http\JsonResponse;
use Modules\Settings\Settings;

class LocaleController
{
    public function __invoke(Settings $settings): JsonResponse
    {
        return ApiResponse::success([
            'supported' => collect(config('localization.supported'))->map(fn (string $locale) => ['code' => $locale, 'name' => config("localization.names.{$locale}")])->values(),
            'default' => $settings->get('app.locale'),
            'fallback' => config('app.fallback_locale'),
        ]);
    }
}
