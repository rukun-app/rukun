<?php
namespace Modules\Engagement\Providers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Incident;
use Modules\Engagement\Services\EngagementScope;
use Modules\Files\Models\StoredFile;
class EngagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang','engagement');
        Gate::before(function (User $user,string $ability,array $arguments): ?bool {
            $file = $arguments[0] ?? null;
            if (! $file instanceof StoredFile || ! in_array($ability,['view','download','update','delete'],true)) { return null; }
            $doc = DB::connection('rukun')->table('engagement_documents')->where('file_id',$file->id)->first();
            if (! $doc) { return null; }
            if (in_array($ability,['update','delete'],true)) { return false; }
            $scope = app(EngagementScope::class);
            return $doc->event_id ? $scope->event($user,CommunityEvent::query()->findOrFail($doc->event_id)) : $scope->incident($user,Incident::query()->findOrFail($doc->incident_id));
        });
    }
}
