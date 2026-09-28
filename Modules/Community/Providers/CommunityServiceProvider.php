<?php

namespace Modules\Community\Providers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Community\Handlers\PopulationHandler;
use Modules\Community\Models\Area;
use Modules\Community\Policies\AreaPolicy;
use Modules\DataTransfer\DataTransferRegistry;
use Modules\Files\Models\StoredFile;

class CommunityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'community');
        Gate::policy(Area::class, AreaPolicy::class);
        $this->app->make(DataTransferRegistry::class)->register($this->app->make(PopulationHandler::class));
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $file = $arguments[0] ?? null;
            if (! $file instanceof StoredFile || ! in_array($ability, ['download', 'view', 'update'], true)) {
                return null;
            }
            $export = DB::connection('rukun')->table('population_exports')->where('file_id', $file->id)->first();
            if ($export) {
                try {
                    app(PopulationHandler::class)->authorize($user, 'export', ['area_id' => Area::query()->findOrFail($export->area_id)->public_id]);
                } catch (\Throwable) {
                    return false;
                }
            }

            return null;
        });
    }
}
