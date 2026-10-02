<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Billing\Models\GatewayCheckout;
use Modules\Billing\Services\GatewayBilling;
use Modules\Wifi\Models\GallonBenefit;
use Modules\Wifi\Services\GallonService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('core:heartbeat', function () {
    $this->info('Core scheduler is running.');
})->purpose('Verify the Core scheduler can run');

Schedule::command('core:heartbeat')->everyMinute();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
Schedule::command('telescope:prune --hours=48')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('realtime:prune-events')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('core:prune-failed-jobs')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('data-transfers:prune')->dailyAt('02:45')->withoutOverlapping();

Schedule::command('billing:notify-due')->dailyAt('07:00')->withoutOverlapping();

Artisan::command('wifi:expire-benefits', function (): void {
    GallonBenefit::query()->where('status', 'active')->where('expires_on', '<', today())->chunkById(100, function ($benefits): void {
        foreach ($benefits as $benefit) {
            app(GallonService::class)->expire($benefit);
        }
    });
    $this->info('Expired gallon benefits processed.');
})->purpose('Expire unused gallon quota and unconfirmed deliveries');
Schedule::command('wifi:expire-benefits')->dailyAt('00:10')->withoutOverlapping();

Artisan::command('billing:reconcile-gateway', function (): void {
    $failures = 0;
    GatewayCheckout::query()->where(function ($query): void {
        $query->whereIn('status', ['reserved', 'review'])->orWhere(function ($query): void {
            $query->whereNull('review_reason')->whereExists(function ($payments): void {
                $payments->selectRaw('1')->from(config('database.connections.core.prefix').'payments as p')->whereColumn('p.id', 'gateway_checkouts.payment_id')->whereIn('p.status', ['paid', 'refunded', 'partially_refunded', 'chargeback', 'partial_chargeback'])->where(function ($q): void {
                    $q->where('gateway_checkouts.status', '!=', 'completed')->orWhere('p.status', '!=', 'paid');
                });
            });
        });
    })->chunkById(100, function ($checkouts) use (&$failures): void {
        foreach ($checkouts as $checkout) {
            try {
                app(GatewayBilling::class)->reconcile($checkout);
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
            }
        }
    });
    $this->info('Gateway reconciliation finished; unresolved provider/errors: '.$failures);
})->purpose('Reconcile reserved gateway orders and recover receipt posting after interrupted callbacks');
Schedule::command('billing:reconcile-gateway')->everyMinute()->withoutOverlapping();
