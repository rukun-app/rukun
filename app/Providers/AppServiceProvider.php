<?php

namespace App\Providers;

use Core\Support\CorrelationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\Settings\Settings;

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
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.auth_register'))->by('register:'.$request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.auth_login'))->by('login:'.$request->ip().':'.sha1(Str::lower((string) $request->input('email')))));
        RateLimiter::for('auth-recovery', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.auth_recovery'))->by('recovery:'.$request->ip().':'.sha1(Str::lower((string) $request->input('email')))));
        RateLimiter::for('files-upload', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.files_upload'))->by('files:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('events-poll', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.events_poll'))->by('events:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('notifications-mutate', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.notifications_mutate'))->by('notifications:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.admin_sensitive'))->by('admin:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('payment-webhooks', fn (Request $request) => Limit::perMinute(app(Settings::class)->get('rate_limit.payment_webhooks'))->by('payment-webhooks:'.$request->ip()));

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
