<?php

namespace Modules\Settings;

use Illuminate\Support\Facades\Cache;
use Modules\Settings\Models\Setting;

class Settings
{
    public function all(bool $publicOnly = false): array
    {
        return collect(SettingsRegistry::DEFINITIONS)
            ->filter(fn (array $definition) => ! $publicOnly || $definition['public'])
            ->mapWithKeys(fn (array $definition, string $key) => [$key => $this->get($key)])
            ->all();
    }

    public function get(string $key): mixed
    {
        $definition = SettingsRegistry::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting: {$key}");

        return Cache::rememberForever('setting:'.$key, fn () => Setting::query()->find($key)?->value ?? $definition['default']);
    }

    public function put(string $key, mixed $value, ?int $actorId): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actorId]);
        Cache::forget('setting:'.$key);
    }
}
