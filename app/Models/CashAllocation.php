<?php

namespace App\Models;

use App\Enums\CashAllocationStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAllocation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'requested_amount',
        'received_amount',
        'utilized_amount',
        'status',
        'requested_by',
        'approved_by',
        'method',
        'reference_no',
        'requested_at',
        'received_at',
        'decided_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'utilized_amount' => 'decimal:2',
            'status' => CashAllocationStatus::class,
            'requested_at' => 'datetime',
            'received_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected function balance(): Attribute
    {
        return Attribute::get(function (): string {
            return bcsub((string) $this->received_amount, (string) $this->utilized_amount, 2);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(CashDisbursement::class);
    }
}
