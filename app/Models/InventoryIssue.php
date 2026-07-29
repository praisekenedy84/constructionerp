<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryIssue extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'requisition_id',
        'inventory_item_id',
        'stock_location_id',
        'quantity',
        'recipient_id',
        'work_section',
        'value',
        'issued_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'value' => 'decimal:2',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
