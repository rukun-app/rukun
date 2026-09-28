<?php

namespace Modules\Payments\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentManager;

class PaymentController
{
    public function store(Request $request, PaymentManager $payments): JsonResponse
    {
        Validator::make(
            ['idempotency_key' => $request->header('Idempotency-Key')],
            ['idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        $validated = $request->validate([
            'reference_type' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'reference_id' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'array', 'max:20'],
        ]);

        try {
            $payment = $payments->create(
                user: $request->user(),
                referenceType: $validated['reference_type'],
                referenceId: $validated['reference_id'],
                amount: $validated['amount'],
                customer: ['name' => $request->user()->name, 'email' => $request->user()->email],
                metadata: $validated['metadata'] ?? [],
            );
        } catch (PaymentGatewayException) {
            return ApiResponse::error(__('payments::messages.checkout_unavailable'), 503, [], 'payment.gateway_unavailable');
        }

        return ApiResponse::success($this->resource($payment), 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->getKey() || $request->user()->can('payments.view-any'), 404);

        return ApiResponse::success($this->resource($payment));
    }

    /** @return array<string, mixed> */
    private function resource(Payment $payment): array
    {
        return [
            'id' => $payment->public_id,
            'provider' => $payment->provider,
            'reference' => ['type' => $payment->reference_type, 'id' => $payment->reference_id],
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'payment_type' => $payment->payment_type,
            'checkout_url' => $payment->checkout_url,
            'paid_at' => $payment->paid_at?->toISOString(),
            'expires_at' => $payment->expires_at?->toISOString(),
            'created_at' => $payment->created_at->toISOString(),
            'updated_at' => $payment->updated_at->toISOString(),
        ];
    }
}
