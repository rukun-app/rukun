<?php

namespace Core\Support;

class ModuleName
{
    public static function isValid(string $name): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) === 1;
    }
}
