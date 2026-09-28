<?php

namespace Modules\Payments\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Events\PaymentStatusChanged;
use Modules\Payments\Gateways\MidtransGateway;
use Modules\Realtime\Contracts\RealtimePublisher;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function (): PaymentGateway {
            if (config('payments.default') !== 'midtrans') {
                throw new \RuntimeException('Unsupported payment gateway: '.config('payments.default'));
            }

            return $this->app->make(MidtransGateway::class);
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'payments');

        Event::listen(PaymentStatusChanged::class, function (PaymentStatusChanged $event): void {
            if ($event->payment->user === null) {
                return;
            }

            app(RealtimePublisher::class)->publish($event->payment->user, 'payment.updated', [
                'payment_id' => $event->payment->public_id,
                'previous_status' => $event->previousStatus->value,
                'status' => $event->payment->status->value,
            ], $event->payment);
        });
    }
}
