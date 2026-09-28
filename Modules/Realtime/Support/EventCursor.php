<?php

namespace Modules\Realtime\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class EventCursor
{
    public static function encode(?string $id, mixed $expiresAt = null): ?string
    {
        return $id === null ? null : Crypt::encryptString(json_encode([
            'id' => $id,
            'expires_at' => $expiresAt?->toISOString(),
        ], JSON_THROW_ON_ERROR));
    }

    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['cursor' => [__('validation.invalid', ['attribute' => 'cursor'])]]);
        }

        if (! is_array($data) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $data['id'] ?? '')) {
            throw ValidationException::withMessages(['cursor' => [__('validation.invalid', ['attribute' => 'cursor'])]]);
        }

        return $data;
    }
}
