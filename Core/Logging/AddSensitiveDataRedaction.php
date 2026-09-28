<?php

namespace Core\Logging;

use Illuminate\Log\Logger;

class AddSensitiveDataRedaction
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new RedactSensitiveContext);
    }
}
