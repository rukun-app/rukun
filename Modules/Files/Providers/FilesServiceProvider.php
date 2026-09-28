<?php

namespace Modules\Files\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Files\Models\StoredFile;
use Modules\Files\Policies\StoredFilePolicy;

class FilesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(StoredFile::class, StoredFilePolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
