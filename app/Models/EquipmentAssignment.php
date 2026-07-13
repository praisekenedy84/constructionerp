<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipmentAssignment extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'equipment_id',
        'project_id',
        'boq_item_id',
        'hours_budgeted',
        'hours_used',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'hours_budgeted' => 'decimal:2',
            'hours_used' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(EquipmentFuelLog::class, 'assignment_id');
    }
}
