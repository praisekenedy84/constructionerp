<?php

namespace App\Models;

use App\Enums\RequisitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionStatusHistory extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'requisition_id',
        'from_status',
        'to_status',
        'actor_id',
        'comment',
        'amendment_reason',
        'original_amount',
        'amended_amount',
        'variance',
        'amendment_items',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => RequisitionStatus::class,
            'to_status' => RequisitionStatus::class,
            'original_amount' => 'decimal:2',
            'amended_amount' => 'decimal:2',
            'variance' => 'decimal:2',
            'amendment_items' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
