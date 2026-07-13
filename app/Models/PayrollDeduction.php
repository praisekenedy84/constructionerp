<?php

namespace App\Models;

use App\Enums\PayrollDeductionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDeduction extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'payroll_item_id',
        'type',
        'amount',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayrollDeductionType::class,
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class);
    }
}
