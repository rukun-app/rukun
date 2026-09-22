<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Core\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    TelescopeServiceProvider::class,
    ModuleServiceProvider::class,
];
