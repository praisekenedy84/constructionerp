<?php

namespace App\Models;

use App\Enums\ComplianceRuleType;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectComplianceRule extends Model
{
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'rule_type',
        'rate',
        'is_active',
        'max_amount',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => ComplianceRuleType::class,
            'rate' => 'decimal:2',
            'is_active' => 'boolean',
            'max_amount' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
