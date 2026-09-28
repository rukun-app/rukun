<?php

namespace Core\Support;

class CorrelationContext
{
    private ?string $requestId = null;

    public function set(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function id(): ?string
    {
        return $this->requestId;
    }

    public function clear(): void
    {
        $this->requestId = null;
    }
}
