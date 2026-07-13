<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkflowConfig extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'level',
        'role_name',
        'threshold_min',
        'threshold_max',
        'escalation_hours',
    ];

    protected function casts(): array
    {
        return [
            'threshold_min' => 'decimal:2',
            'threshold_max' => 'decimal:2',
            'escalation_hours' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
