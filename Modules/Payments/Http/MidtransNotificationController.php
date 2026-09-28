<?php

namespace Modules\Payments\Http;

use Core\Http\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Services\PaymentManager;

class MidtransNotificationController
{
    public function __invoke(Request $request, PaymentManager $payments, PaymentGateway $gateway): JsonResponse
    {
        $payload = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'status_code' => ['required', 'string', 'max:10'],
            'gross_amount' => ['required', 'string', 'max:30'],
            'signature_key' => ['required', 'string', 'size:128'],
            'transaction_status' => ['required', 'string', 'max:32'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', 'string', 'max:64'],
            'fraud_status' => ['nullable', 'string', 'max:32'],
            'status_message' => ['nullable', 'string', 'max:500'],
            'currency' => ['nullable', 'string', 'size:3'],
            'transaction_time' => ['nullable', 'string', 'max:40'],
            'settlement_time' => ['nullable', 'string', 'max:40'],
            'expiry_time' => ['nullable', 'string', 'max:40'],
        ]);

        if (! config('payments.midtrans.production') && str_starts_with($payload['order_id'], 'payment_notif_test_')) {
            if (! $gateway->verifyNotification($payload)) {
                return ApiResponse::error(__('payments::messages.invalid_signature'), 401, [], 'payment.invalid_signature');
            }

            return ApiResponse::success(['received' => true, 'test' => true]);
        }

        try {
            $payment = $payments->handleNotification($payload);
        } catch (ModelNotFoundException) {
            return ApiResponse::error(__('payments::messages.unknown_payment'), 404, [], 'payment.not_found');
        } catch (InvalidArgumentException $exception) {
            $signatureFailure = str_contains($exception->getMessage(), 'signature');

            return ApiResponse::error(
                __($signatureFailure ? 'payments::messages.invalid_signature' : 'payments::messages.invalid_notification'),
                $signatureFailure ? 401 : 422,
                [],
                $signatureFailure ? 'payment.invalid_signature' : 'payment.invalid_notification',
            );
        }

        return ApiResponse::success(['received' => true]);
    }
}
