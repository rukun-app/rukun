<?php

namespace Modules\Payments\Contracts;

use Modules\Payments\Models\Payment;
use Modules\Payments\ValueObjects\CheckoutSession;

interface PaymentGateway
{
    public function createCheckout(Payment $payment, array $customer = []): CheckoutSession;

    public function status(string $providerOrderId): array;

    public function verifyNotification(array $payload): bool;
}
