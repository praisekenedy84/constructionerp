<?php

namespace App\Traits;

use App\Models\AuditLog;

trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(fn ($model) => static::writeAudit($model, 'created', null));
        static::updated(fn ($model) => static::writeAudit($model, 'updated', $model->getOriginal()));
        static::deleted(fn ($model) => static::writeAudit($model, 'deleted', $model->getOriginal()));
    }

    private static function writeAudit($model, string $action, ?array $before): void
    {
        AuditLog::create([
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'action' => $action,
            'before_data' => $before,
            'after_data' => $model->toArray(),
            'performed_by' => auth()->id(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
