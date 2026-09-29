<?php

namespace Modules\Wifi\Providers;

use Illuminate\Support\ServiceProvider;

class WifiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'wifi');
    }
}
