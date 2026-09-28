<?php

use Modules\Identity\Support\LoginIdentifier;

it('normalizes equivalent identifiers consistently', function (string $input, string $expected) {
    expect(LoginIdentifier::normalize($input))->toBe($expected);
})->with([
    [' Warga@Example.test ', 'warga@example.test'],
    ['0812-3456-7890', '+6281234567890'],
    ['6281234567890', '+6281234567890'],
    ['+62 (812) 3456 7890', '+6281234567890'],
]);
