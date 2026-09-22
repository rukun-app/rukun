<?php

namespace Modules\Access\Providers;

use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
