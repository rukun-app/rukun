<?php

namespace Modules\Community\Services;

class SensitiveIdentifier
{
    public static function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, app('encrypter')->getKey());
    }
}
