<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'requisition_id',
        'boq_item_id',
        'inventory_item_id',
        'description',
        'unit',
        'quantity',
        'unit_cost',
        'line_total',
        'original_quantity',
        'original_unit_cost',
        'original_line_total',
        'original_description',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'original_quantity' => 'decimal:3',
            'original_unit_cost' => 'decimal:2',
            'original_line_total' => 'decimal:2',
            'details' => 'array',
        ];
    }

    public function wasAmended(): bool
    {
        return $this->original_quantity !== null
            || $this->original_unit_cost !== null
            || $this->original_line_total !== null;
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
