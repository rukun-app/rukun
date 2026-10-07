<?php

namespace Modules\Civic\Providers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Civic\Models\Announcement;
use Modules\Civic\Models\CivicCase;
use Modules\Civic\Services\CivicScope;
use Modules\Files\Models\StoredFile;

class CivicServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'civic');
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $file = $arguments[0] ?? null;
            if (! $file instanceof StoredFile || ! in_array($ability, ['view', 'download', 'update', 'delete'], true)) {
                return null;
            }
            $doc = DB::connection('rukun')->table('civic_documents')->where('file_id', $file->id)->first();
            if (! $doc) {
                return null;
            }
            if (in_array($ability, ['update', 'delete'], true)) {
                return false;
            }
            $scope = app(CivicScope::class);

            return $doc->announcement_id ? $scope->announcement($user, Announcement::query()->findOrFail($doc->announcement_id)) : $scope->case($user, CivicCase::query()->findOrFail($doc->case_id));
        });
    }
}
