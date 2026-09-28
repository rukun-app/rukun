<?php

namespace Modules\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Models\Payment;
use Modules\Payments\ValueObjects\CheckoutSession;

class MidtransGateway implements PaymentGateway
{
    public function createCheckout(Payment $payment, array $customer = []): CheckoutSession
    {
        $response = $this->client()->post($this->snapUrl().'/snap/v1/transactions', array_filter([
            'transaction_details' => ['order_id' => $payment->provider_order_id, 'gross_amount' => $payment->amount],
            'customer_details' => array_filter([
                'first_name' => $customer['name'] ?? null,
                'email' => $customer['email'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ]),
            'credit_card' => ['secure' => true],
        ], fn (mixed $value) => $value !== []));

        if (! $response->successful() || ! is_string($response->json('token')) || ! is_string($response->json('redirect_url'))) {
            throw new PaymentGatewayException('Midtrans rejected the checkout request (HTTP '.$response->status().').');
        }

        return new CheckoutSession($response->json('token'), $response->json('redirect_url'));
    }

    public function status(string $providerOrderId): array
    {
        $response = $this->client()->get($this->apiUrl().'/v2/'.rawurlencode($providerOrderId).'/status');
        if (! $response->successful()) {
            throw new PaymentGatewayException('Unable to retrieve the Midtrans transaction status (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function verifyNotification(array $payload): bool
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                return false;
            }
        }

        $signature = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$this->serverKey());

        return hash_equals($signature, $payload['signature_key']);
    }

    private function client(): PendingRequest
    {
        if (! config('payments.midtrans.enabled')) {
            throw new PaymentGatewayException('Midtrans is not enabled.');
        }

        return Http::acceptJson()->asJson()->withBasicAuth($this->serverKey(), '')
            ->connectTimeout(3)->timeout(config('payments.midtrans.timeout'));
    }

    private function serverKey(): string
    {
        $key = config('payments.midtrans.server_key');
        if (! is_string($key) || $key === '') {
            throw new PaymentGatewayException('Midtrans server key is not configured.');
        }

        return $key;
    }

    private function snapUrl(): string
    {
        return rtrim(config('payments.midtrans.snap_url') ?: (config('payments.midtrans.production') ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com'), '/');
    }

    private function apiUrl(): string
    {
        return rtrim(config('payments.midtrans.api_url') ?: (config('payments.midtrans.production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com'), '/');
    }
}
