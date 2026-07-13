<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryTransaction extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'inventory_item_id',
        'stock_location_id',
        'type',
        'quantity',
        'unit_cost',
        'reference_entity_type',
        'reference_entity_id',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryTransactionType::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function referenceEntity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_entity_type', 'reference_entity_id');
    }
}
