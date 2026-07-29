<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDisbursement extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::created(function (CashDisbursement $disbursement): void {
            CashAllocation::whereKey($disbursement->cash_allocation_id)
                ->increment('utilized_amount', (string) $disbursement->amount);
        });

        static::deleted(function (CashDisbursement $disbursement): void {
            CashAllocation::whereKey($disbursement->cash_allocation_id)
                ->decrement('utilized_amount', (string) $disbursement->amount);
        });
    }

    protected $fillable = [
        'requisition_id',
        'expense_id',
        'cash_allocation_id',
        'amount',
        'method',
        'payee',
        'account_name',
        'reference_no',
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

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
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
