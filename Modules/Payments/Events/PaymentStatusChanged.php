<?php

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;

class PaymentStatusChanged
{
    use Dispatchable;

    public function __construct(public Payment $payment, public PaymentStatus $previousStatus) {}
}
