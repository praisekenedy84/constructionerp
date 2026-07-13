<?php

namespace App\Models;

use App\Enums\GoodsReceiptCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceipt extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'purchase_order_id',
        'quantity_received',
        'condition',
        'received_by',
        'received_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'condition' => GoodsReceiptCondition::class,
            'received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
