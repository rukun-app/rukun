<?php

namespace Core\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RedactSensitiveContext implements ProcessorInterface
{
    private const SENSITIVE_FRAGMENTS = ['authorization', 'cookie', 'password', 'token', 'secret', 'credential', 'access_key', 'nik', 'kk_number'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->redact($record->context), extra: $this->redact($record->extra));
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
