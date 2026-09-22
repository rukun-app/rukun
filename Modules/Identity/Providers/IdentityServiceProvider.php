<?php

namespace Modules\Identity\Providers;

use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
