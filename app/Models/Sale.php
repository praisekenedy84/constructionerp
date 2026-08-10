<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'phase_id',
        'sale_code',
        'status',
        'contract_amount',
        'profit_amount',
        'collected_amount',
        'converted_at',
        'converted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'contract_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaleReceivablePayment::class);
    }

    public function outstandingAmount(): string
    {
        if ($this->profit_amount === null) {
            return '0.00';
        }

        return bcsub((string) $this->profit_amount, (string) $this->collected_amount, 2);
    }

    public function isLossReceivable(): bool
    {
        return $this->isConverted() && bccomp((string) $this->profit_amount, '0', 2) === -1;
    }

    public function isConverted(): bool
    {
        return in_array($this->status, [
            SaleStatus::Receivable,
            SaleStatus::PartiallyPaid,
            SaleStatus::Paid,
        ], true);
    }
}
