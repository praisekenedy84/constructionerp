<?php

namespace App\Models;

use App\Enums\CompanyDebtStatus;
use App\Enums\CompanyDebtType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyDebt extends Model
{
    protected $fillable = [
        'type',
        'creditor_name',
        'original_amount',
        'outstanding_amount',
        'status',
        'money_account_id',
        'deposit_transaction_id',
        'notes',
        'recorded_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CompanyDebtType::class,
            'status' => CompanyDebtStatus::class,
            'original_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function depositTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class, 'deposit_transaction_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CompanyDebtPayment::class);
    }
}
