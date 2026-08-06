<?php

namespace App\Models;

use App\Enums\ComplianceAllocationLevel;
use App\Enums\ComplianceCalculationType;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectComplianceItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'compliance_rule_id',
        'calculation_type',
        'rate',
        'fixed_amount',
        'amount',
        'allocation_level',
        'phase_id',
        'valuation_id',
        'attached_at',
        'migrated_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'calculation_type' => ComplianceCalculationType::class,
            'rate' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'allocation_level' => ComplianceAllocationLevel::class,
            'attached_at' => 'datetime',
            'migrated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function complianceRule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class);
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

    public function events(): HasMany
    {
        return $this->hasMany(ProjectComplianceItemEvent::class);
    }

    public function scopeOnContract($query)
    {
        return $query->where('allocation_level', ComplianceAllocationLevel::Contract);
    }

    public function scopeOnPhase($query)
    {
        return $query->where('allocation_level', ComplianceAllocationLevel::Phase);
    }
}
