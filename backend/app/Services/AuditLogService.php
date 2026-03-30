<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    public function log(
        string $action,
        ?int $incidentId = null,
        ?int $actorId = null,
        string $actorType = 'system',
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        return AuditLog::create([
            'incident_id' => $incidentId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }
}
