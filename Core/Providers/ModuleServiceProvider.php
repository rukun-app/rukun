<?php

namespace Core\Providers;

use Core\Console\BootstrapAdminCommand;
use Core\Console\GenerateApiDocsCommand;
use Core\Console\MakeModuleCommand;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BootstrapAdminCommand::class,
                GenerateApiDocsCommand::class,
                MakeModuleCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        foreach (glob(base_path('Modules/*/Providers/*ServiceProvider.php')) ?: [] as $file) {
            $module = basename(dirname(dirname($file)));
            $provider = 'Modules\\'.$module.'\\Providers\\'.basename($file, '.php');
            $this->app->register($provider);
        }
    }
}
