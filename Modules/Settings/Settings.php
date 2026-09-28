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

        $value = Cache::rememberForever('setting:'.$key, fn () => Setting::query()->find($key)?->value ?? $this->fallback($definition));

        return $this->cast($value, $definition['type']);
    }

    public function source(string $key): string
    {
        $definition = SettingsRegistry::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting: {$key}");
        if (Setting::query()->whereKey($key)->exists()) {
            return 'database';
        }

        return config($definition['fallback']) !== null ? 'environment' : 'default';
    }

    public function put(string $key, mixed $value, ?int $actorId): void
    {
        $definition = SettingsRegistry::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Unknown setting: {$key}");
        Setting::query()->updateOrCreate(['key' => $key], [
            'value' => $value,
            'type' => $definition['type'],
            'group' => $definition['group'],
            'updated_by' => $actorId,
        ]);
        Cache::forget('setting:'.$key);
    }

    public function forget(string $key): void
    {
        abort_unless(array_key_exists($key, SettingsRegistry::DEFINITIONS), 422);
        Setting::query()->whereKey($key)->delete();
        Cache::forget('setting:'.$key);
    }

    private function fallback(array $definition): mixed
    {
        $value = config($definition['fallback']);
        if ($value === null) {
            return $definition['default'];
        }

        return $this->cast($value, $definition['type']);
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL) : (bool) $value,
            'integer' => (int) $value,
            'array' => (array) $value,
            default => (string) $value,
        };
    }
}
