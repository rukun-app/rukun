<?php

use Core\Support\ModuleName;

it('accepts safe module names', function () {
    expect(ModuleName::isValid('Example2'))->toBeTrue()
        ->and(ModuleName::isValid('../Escape'))->toBeFalse()
        ->and(ModuleName::isValid('bad name'))->toBeFalse();
});
