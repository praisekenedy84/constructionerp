<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentFuelLog extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'equipment_id',
        'assignment_id',
        'liters',
        'cost',
        'date',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'liters' => 'decimal:2',
            'cost' => 'decimal:2',
            'date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EquipmentAssignment::class, 'assignment_id');
    }
}
