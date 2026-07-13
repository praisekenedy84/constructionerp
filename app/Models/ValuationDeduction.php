<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValuationDeduction extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'valuation_id',
        'rule_type',
        'rate',
        'amount',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }
}
