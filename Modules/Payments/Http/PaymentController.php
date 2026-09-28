<?php

namespace Modules\Payments\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Models\Payment;

class PaymentController
{
    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->getKey() || $request->user()->can('payments.view-any'), 404);

        return ApiResponse::success([
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
        ]);
    }
}
