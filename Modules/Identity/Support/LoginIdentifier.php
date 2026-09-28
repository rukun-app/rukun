<?php

namespace Modules\Identity\Support;

use Illuminate\Validation\ValidationException;

class LoginIdentifier
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            return mb_strtolower($value);
        }

        return self::phone($value);
    }

    public static function phone(string $value): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($value));
        if (str_starts_with($phone, '08')) {
            $phone = '+62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '628')) {
            $phone = '+'.$phone;
        }

        if (! preg_match('/^\+628[0-9]{8,11}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => __('api.auth.invalid_phone')]);
        }

        return $phone;
    }
}
