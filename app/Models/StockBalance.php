<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    public $timestamps = false;

    const CREATED_AT = null;

    protected $fillable = [
        'inventory_item_id',
        'stock_location_id',
        'quantity_on_hand',
        'average_cost',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'average_cost' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
