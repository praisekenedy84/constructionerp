<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDisbursement extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'requisition_id',
        'cash_allocation_id',
        'amount',
        'method',
        'payee',
        'disbursed_by',
        'disbursed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'disbursed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function cashAllocation(): BelongsTo
    {
        return $this->belongsTo(CashAllocation::class);
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
