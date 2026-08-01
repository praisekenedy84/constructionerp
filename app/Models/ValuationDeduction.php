<?php

namespace App\Models;

use App\Enums\ComplianceCalculationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValuationDeduction extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'valuation_id',
        'compliance_rule_id',
        'name',
        'calculation_type',
        'rule_type',
        'rate',
        'fixed_amount',
        'amount',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'calculation_type' => ComplianceCalculationType::class,
            'rate' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class);
    }

    public function complianceRule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class);
    }
}
