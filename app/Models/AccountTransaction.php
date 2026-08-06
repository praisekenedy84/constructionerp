<?php

namespace App\Models;

use App\Enums\AccountTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountTransaction extends Model
{
    protected $fillable = [
        'money_account_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'reference_no',
        'method',
        'related_account_id',
        'reference_entity_type',
        'reference_entity_id',
        'recorded_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountTransactionType::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function relatedAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'related_account_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isCredit(): bool
    {
        return in_array($this->type, [
            AccountTransactionType::Deposit,
            AccountTransactionType::TransferIn,
            AccountTransactionType::OpeningBalance,
            AccountTransactionType::ReceivablePayment,
        ], true);
    }
}
