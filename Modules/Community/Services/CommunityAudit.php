<?php

namespace Modules\Community\Services;

use Core\Audit\AuditEvent;
use Core\Support\CorrelationContext;
use Illuminate\Database\Eloquent\Model;

class CommunityAudit
{
    public static function record(string $event, Model $subject, array $metadata = [], ?int $actorId = null): void
    {
        // Use the same physical transaction as business mutations, retaining the Core audit schema.
        $audit = new AuditEvent;
        $audit->setConnection('rukun');
        $audit->setTable(config('database.connections.core.prefix').'audit_events');
        $audit->fill(['actor_id' => $actorId ?? auth()->id(), 'event' => $event, 'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(), 'metadata' => ['request_id' => app(CorrelationContext::class)->id(), ...$metadata],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()])->save();
    }
}
