<?php

namespace Modules\Billing\Providers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Console\InvoiceDueSoonCommand;
use Modules\Billing\Models\Expense;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Services\GatewayBilling;
use Modules\Community\Models\Area;
use Modules\Community\Services\ScopeResolver;
use Modules\Files\Models\StoredFile;
use Modules\Payments\Events\PaymentStatusChanged;
use Throwable;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'billing');
        if ($this->app->runningInConsole()) {
            $this->commands([InvoiceDueSoonCommand::class]);
        }
        Event::listen(PaymentStatusChanged::class, function (PaymentStatusChanged $event): void {
            if ($event->payment->reference_type === 'billing.gateway') {
                try {
                    app(GatewayBilling::class)->synchronize($event->payment);
                } catch (Throwable $exception) {
                    // The durable checkout remains available to the reconciliation scheduler.
                    report($exception);
                }
            }
        });
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $file = $arguments[0] ?? null;
            if (! $file instanceof StoredFile || ! in_array($ability, ['view', 'download', 'update', 'delete'], true)) {
                return null;
            }
            if (DB::connection('rukun')->table('civic_documents')->where('file_id', $file->id)->exists() || DB::connection('rukun')->table('engagement_documents')->where('file_id', $file->id)->exists()) {
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
