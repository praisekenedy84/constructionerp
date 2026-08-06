<?php

namespace App\Models;

use App\Enums\EquipmentMaintenanceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentMaintenance extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'equipment_id',
        'purchase_order_id',
        'type',
        'cost',
        'description',
        'date',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => EquipmentMaintenanceType::class,
            'cost' => 'decimal:2',
            'date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
