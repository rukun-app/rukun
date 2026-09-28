<?php

namespace Modules\DataTransfer\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\DataTransfer\Console\PruneDataTransfersCommand;
use Modules\DataTransfer\DataTransferRegistry;
use Modules\DataTransfer\Handlers\IdentityUsersHandler;

class DataTransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DataTransferRegistry::class);
    }

    public function boot(DataTransferRegistry $registry): void
    {
        $registry->register($this->app->make(IdentityUsersHandler::class));
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'data-transfer');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneDataTransfersCommand::class]);
        }
    }
}
