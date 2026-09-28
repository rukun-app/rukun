<?php

namespace App\Providers;

use Core\Support\CorrelationContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CorrelationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::createPayloadUsing(fn () => ['request_id' => app(CorrelationContext::class)->id()]);
        Queue::before(function (JobProcessing $event): void {
            $requestId = $event->job->payload()['request_id'] ?? null;
            app(CorrelationContext::class)->set($requestId);
            Log::withContext(['request_id' => $requestId, 'job_id' => $event->job->getJobId(), 'job_type' => $event->job->resolveName(), 'queue' => $event->job->getQueue()]);
        });
        $clear = function (JobProcessed|JobExceptionOccurred $event): void {
            app(CorrelationContext::class)->clear();
            Log::withoutContext();
        };
        Queue::after($clear);
        Queue::exceptionOccurred($clear);
    }
}
