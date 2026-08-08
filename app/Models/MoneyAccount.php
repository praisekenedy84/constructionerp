<?php

namespace App\Models;

use App\Enums\MoneyAccountType;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MoneyAccount extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'bank_name',
        'type',
        'balance',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MoneyAccountType::class,
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function isManagerAccount(): bool
    {
        return $this->type === MoneyAccountType::Manager;
    }

    public function isFinanceAccount(): bool
    {
        return $this->type === MoneyAccountType::Finance;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }
}
