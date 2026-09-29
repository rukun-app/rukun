<?php

namespace Modules\Billing\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Console\InvoiceDueSoonCommand;
use Modules\Billing\Models\Expense;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Community\Models\Area;
use Modules\Community\Services\ScopeResolver;
use Modules\Files\Models\StoredFile;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'billing');
        if ($this->app->runningInConsole()) {
            $this->commands([InvoiceDueSoonCommand::class]);
        }
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $file = $arguments[0] ?? null;
            if (! $file instanceof StoredFile || ! in_array($ability, ['view', 'download', 'update', 'delete'], true)) {
                return null;
            }
            $submissions = PaymentSubmission::query()->where('proof_file_id', $file->id)->get();
            $expenses = Expense::query()->where('proof_file_id', $file->id)->get();
            if ($submissions->isEmpty() && $expenses->isEmpty()) {
                return null;
            }
            if (in_array($ability, ['update', 'delete'], true)) {
                return false;
            }
            if ($file->owner_id === $user->id) {
                return null;
            }
            $scopes = app(ScopeResolver::class);
            foreach ($submissions as $submission) {
                if ($scopes->allows($user, 'payments.manual.view', Area::query()->findOrFail($submission->area_id))) {
                    return true;
                }
            }
            foreach ($expenses as $expense) {
                if ($scopes->allows($user, 'ledger.view', Area::query()->findOrFail($expense->area_id))) {
                    return true;
                }
            }

            return false;
        });
    }
}
