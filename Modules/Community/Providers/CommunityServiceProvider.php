<?php

namespace Modules\Community\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Community\Models\Area;
use Modules\Community\Policies\AreaPolicy;

class CommunityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'community');
        Gate::policy(Area::class, AreaPolicy::class);
    }
}
