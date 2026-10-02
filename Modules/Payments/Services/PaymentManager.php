<?php

namespace Modules\Payments\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Core\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentStatusChanged;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentNotification;
use Throwable;

class PaymentManager
{
    public function __construct(private PaymentGateway $gateway) {}

    public function create(User $user, string $referenceType, string $referenceId, int $amount, array $customer = [], array $metadata = []): Payment
    {
        return $this->start($this->prepare($user, $referenceType, $referenceId, $amount, $metadata), $customer);
    }

    public function prepare(User $user, string $referenceType, string $referenceId, int $amount, array $metadata = [], ?string $connection = null): Payment
    {
        if ($amount < 1 || $referenceType === '' || $referenceId === '') {
            throw new InvalidArgumentException('A positive amount and business reference are required.');
        }

        $publicId = (string) Str::uuid();
        $model = new Payment;
        if ($connection !== null) {
            $model->setConnection($connection)->setTable(config('database.connections.core.prefix').'payments');
        }
        $payment = $model->newQuery()->create([
            'public_id' => $publicId,
            'user_id' => $user->getKey(),
            'provider' => 'midtrans',
            'provider_order_id' => 'CORE-'.$publicId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Creating,
            'metadata' => $metadata ?: null,
        ]);

        return $payment;
    }

    public function start(Payment $payment, array $customer = []): Payment
    {
        if ($payment->expires_at?->isPast()) {
            $payment->newQuery()->whereKey($payment->id)->where('status', PaymentStatus::Creating->value)->update(['status' => PaymentStatus::Failed->value]);

            return $payment->refresh();
        }
        // Claim the outbound attempt before HTTP; a timeout must never create another order.
        if (! $payment->newQuery()->whereKey($payment->id)->where('status', PaymentStatus::Creating->value)->update(['status' => PaymentStatus::ProviderUnknown->value])) {
            return $payment->refresh();
        }
        try {
            $checkout = $this->gateway->createCheckout($payment, $customer);
            $payment->newQuery()->whereKey($payment->id)->update(['checkout_token' => $checkout->token, 'checkout_url' => $checkout->redirectUrl]);
            $payment->newQuery()->whereKey($payment->id)->where('status', PaymentStatus::ProviderUnknown->value)->update(['status' => PaymentStatus::Pending->value]);
        } catch (Throwable $exception) {
            throw $exception instanceof PaymentGatewayException
                ? $exception
                : new PaymentGatewayException('Unable to create payment checkout.', previous: $exception);
        }

        Audit::record('payment.created', $payment, [
            'reference_type' => $payment->reference_type,
            'reference_id' => $payment->reference_id,
            'amount' => $payment->amount,
            'currency' => 'IDR',
        ]);

        return $payment->refresh();
    }

    public function reconcile(Payment $payment): Payment
    {
        $payload = $this->gateway->status($payment->provider_order_id);
        if (($payload['order_id'] ?? null) !== $payment->provider_order_id) {
            throw new InvalidArgumentException('Provider returned a different payment order.');
        }

        return $this->processNotification($payload, true);
    }

    public function handleNotification(array $payload): Payment
    {
        return $this->processNotification($payload, false);
    }

    private function processNotification(array $payload, bool $verifiedStatus): Payment
    {
        if (! $this->gateway->verifyNotification($payload)) {
            throw new InvalidArgumentException('Invalid payment notification signature.');
        }

        $candidate = Payment::query()->where('provider_order_id', $payload['order_id'])->firstOrFail();
        if (! $verifiedStatus && $candidate->reference_type === 'billing.gateway') {
            return $this->reconcile($candidate);
        }

        return DB::transaction(function () use ($payload): Payment {
            $payment = Payment::query()->where('provider_order_id', $payload['order_id'])->lockForUpdate()->firstOrFail();
            $this->assertAmount($payment, (string) $payload['gross_amount'], $payload['currency'] ?? null);
            $providerStatus = (string) ($payload['transaction_status'] ?? '');
            $status = $this->mapStatus($providerStatus, $payload['fraud_status'] ?? null, (string) $payload['status_code']);
            if ($payment->status->isPaid() && in_array($status, [
                PaymentStatus::Creating,
                PaymentStatus::Pending,
                PaymentStatus::Authorized,
                PaymentStatus::Failed,
                PaymentStatus::ProviderUnknown,
                PaymentStatus::Cancelled,
                PaymentStatus::Expired,
            ], true)) {
                $status = $payment->status;
            }
            $safePayload = array_intersect_key($payload, array_flip([
                'order_id', 'transaction_id', 'transaction_status', 'status_code', 'status_message',
                'gross_amount', 'currency', 'payment_type', 'fraud_status', 'transaction_time',
                'settlement_time', 'expiry_time',
            ]));
            $fingerprint = hash('sha256', implode('|', [
                $payload['order_id'], $payload['transaction_id'] ?? '', $providerStatus,
                $payload['status_code'], $payload['gross_amount'],
            ]));

            if (PaymentNotification::query()->where('fingerprint', $fingerprint)->exists()) {
                return $payment;
            }

            $paidAt = $payment->paid_at;
            if ($status->isPaid() && ! $paidAt) {
                if ($payment->reference_type === 'billing.gateway') {
                    $value = $payload['settlement_time'] ?? '';
                    try {
                        $paidAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'Asia/Jakarta');
                    } catch (Throwable) {
                        throw new InvalidArgumentException('Invalid gateway settlement time.');
                    }
                    if (! $paidAt || $paidAt->format('Y-m-d H:i:s') !== $value || $paidAt->isFuture()) {
                        throw new InvalidArgumentException('Invalid gateway settlement time.');
                    }
                    $paidAt = $paidAt->setTimezone(config('app.timezone'));
                } else {
                    $paidAt = now();
                }
            }
            $previous = $payment->status;
            $payment->update([
                'status' => $status,
                'provider_transaction_id' => $payload['transaction_id'] ?? $payment->provider_transaction_id,
                'payment_type' => $payload['payment_type'] ?? $payment->payment_type,
                'provider_data' => $safePayload,
                'paid_at' => $paidAt,
            ]);
            PaymentNotification::query()->create([
                'payment_id' => $payment->getKey(),
                'provider' => $payment->provider,
                'fingerprint' => $fingerprint,
                'provider_status' => $providerStatus,
                'resulting_status' => $status->value,
                'payload' => $safePayload,
                'processed_at' => now(),
            ]);
            Audit::record('payment.notification_processed', $payment, [
                'provider_status' => $providerStatus,
                'status' => $status->value,
            ]);

            if ($previous !== $status) {
                DB::afterCommit(fn () => PaymentStatusChanged::dispatch($payment->refresh(), $previous));
            }

            return $payment->refresh();
        });
    }

    private function mapStatus(string $status, mixed $fraudStatus, string $statusCode): PaymentStatus
    {
        if (in_array($status, ['capture', 'settlement'], true) && $statusCode !== '200') {
            return PaymentStatus::ProviderUnknown;
        }

        if ($status === 'capture' && $fraudStatus !== null) {
            return match (strtolower((string) $fraudStatus)) {
                'accept' => PaymentStatus::Paid,
                'deny' => PaymentStatus::Denied,
                default => PaymentStatus::Pending,
            };
        }

        return match ($status) {
            'pending' => PaymentStatus::Pending,
            'authorize' => PaymentStatus::Authorized,
            'capture', 'settlement' => PaymentStatus::Paid,
            'deny' => PaymentStatus::Denied,
            'cancel' => PaymentStatus::Cancelled,
            'expire' => PaymentStatus::Expired,
            'failure' => PaymentStatus::Failed,
            'refund' => PaymentStatus::Refunded,
            'partial_refund' => PaymentStatus::PartiallyRefunded,
            'chargeback' => PaymentStatus::Chargeback,
            'partial_chargeback' => PaymentStatus::PartialChargeback,
            default => PaymentStatus::ProviderUnknown,
        };
    }

    private function assertAmount(Payment $payment, string $grossAmount, mixed $currency): void
    {
        if (! preg_match('/^([0-9]+)(?:\.0+)?$/', $grossAmount, $matches)) {
            throw new InvalidArgumentException('Payment notification amount does not match.');
        }

        $normalized = ltrim($matches[1], '0') ?: '0';
        if ((string) $payment->amount !== $normalized || ($currency !== null && strtoupper((string) $currency) !== $payment->currency)) {
            throw new InvalidArgumentException('Payment notification amount does not match.');
        }
    }
}
