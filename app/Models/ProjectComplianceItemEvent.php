<?php

namespace App\Models;

use App\Enums\ComplianceItemEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectComplianceItemEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_compliance_item_id',
        'event_type',
        'phase_id',
        'valuation_id',
        'meta',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ComplianceItemEventType::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProjectComplianceItem::class, 'project_compliance_item_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
