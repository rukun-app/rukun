<?php

namespace Modules\Payments\ValueObjects;

readonly class CheckoutSession
{
    public function __construct(public string $token, public string $redirectUrl) {}
}
