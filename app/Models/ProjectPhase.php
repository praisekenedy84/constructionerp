<?php

namespace App\Models;

use App\Enums\PhaseStatus;
use App\Enums\RetentionStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectPhase extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'sequence_no',
        'name',
        'status',
        'disbursed_amount',
        'retention_held_amount',
        'retention_released_amount',
        'retention_forfeited_amount',
        'other_deductions_amount',
        'phase_net_budget',
        'retention_status',
        'retention_released_at',
        'retention_forfeited_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
            'status' => PhaseStatus::class,
            'disbursed_amount' => 'decimal:2',
            'retention_held_amount' => 'decimal:2',
            'retention_released_amount' => 'decimal:2',
            'retention_forfeited_amount' => 'decimal:2',
            'other_deductions_amount' => 'decimal:2',
            'phase_net_budget' => 'decimal:2',
            'retention_status' => RetentionStatus::class,
            'retention_released_at' => 'datetime',
            'retention_forfeited_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class, 'phase_id');
    }
}
