<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function log(
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        ?int $shopId = null,
    ): void {
        try {
            AuditLog::create([
                'user_id' => Auth::id(),
                'shop_id' => $shopId ?? ($entity->shop_id ?? null),
                'action' => $action,
                'entity_type' => class_basename($entity),
                'entity_id' => $entity->getKey(),
                'before_json' => $before,
                'after_json' => $after,
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            // audit log failure should never break the main request
            \Log::warning('Audit log failed: '.$e->getMessage());
        }
    }
}
