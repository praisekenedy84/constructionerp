<?php

namespace App\Models;

use App\Enums\ApprovalStepStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalStep extends Model
{
    use LogsActivity;

    protected $fillable = [
        'requisition_id',
        'level',
        'required_role',
        'status',
        'assigned_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'status' => ApprovalStepStatus::class,
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class);
    }
}
