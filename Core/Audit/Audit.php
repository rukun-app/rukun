<?php

namespace Core\Audit;

use Core\Support\CorrelationContext;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $event, ?Model $subject = null, array $metadata = []): void
    {
        AuditEvent::query()->create([
            'actor_id' => auth()->id(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => array_filter(['request_id' => app(CorrelationContext::class)->id(), ...$metadata], fn ($value) => $value !== null) ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
