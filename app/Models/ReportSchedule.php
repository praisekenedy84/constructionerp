<?php

namespace App\Models;

use App\Enums\ReportScheduleFrequency;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportSchedule extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'report_slug',
        'project_id',
        'frequency',
        'recipients',
        'parameters',
        'last_run_at',
        'next_run_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => ReportScheduleFrequency::class,
            'recipients' => 'array',
            'parameters' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
