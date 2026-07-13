<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function write(
        string $entityType,
        int|string $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $performedBy = null,
    ): AuditLog {
        return AuditLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_data' => $before,
            'after_data' => $after,
            'performed_by' => $performedBy ?? Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
