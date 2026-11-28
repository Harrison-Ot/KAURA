<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;

class AccessAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $event, ?User $actor, ?User $subject, string $reason, ?array $before = null, ?array $after = null, ?string $resourceType = null, ?int $resourceId = null): void
    {
        AuditEvent::create([
            'event' => $event,
            'actor_id' => $actor?->id,
            'subject_id' => $subject?->id,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'created_at' => now(),
        ]);
    }
}
