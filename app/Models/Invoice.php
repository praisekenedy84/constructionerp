<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceTaxMode;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'project_id',
        'phase_id',
        'invoice_date',
        'due_date',
        'description',
        'amount_before_tax',
        'tax_mode',
        'tax_type',
        'tax_rate',
        'tax_amount',
        'deduction_type',
        'deduction_rate',
        'deduction_amount',
        'total_amount',
        'status',
        'issued_at',
        'printed_at',
        'paid_at',
        'created_by',
    ];

    protected $appends = [
        'paid_amount',
        'outstanding_amount',
        'payment_status',
        'display_status',
        'pending_days',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'amount_before_tax' => 'decimal:2',
            'tax_mode' => InvoiceTaxMode::class,
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'deduction_rate' => 'decimal:4',
            'deduction_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'printed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected function paidAmount(): Attribute
    {
        return Attribute::get(fn (): string => bcadd(
            (string) ($this->payments_sum_amount_paid ?? $this->payments()->sum('amount_paid')),
            '0',
            2,
        ));
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(function (): string {
            $outstanding = bcsub((string) $this->total_amount, $this->paid_amount, 2);

            return bccomp($outstanding, '0', 2) === 1 ? $outstanding : '0.00';
        });
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if (bccomp($this->paid_amount, (string) $this->total_amount, 2) >= 0) {
                return 'paid';
            }

            return bccomp($this->paid_amount, '0', 2) === 1 ? 'partially_paid' : 'unpaid';
        });
    }

    protected function displayStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->status === InvoiceStatus::Paid) {
                return InvoiceStatus::Paid->value;
            }

            if ($this->status !== InvoiceStatus::Draft && $this->due_date?->lt(today())) {
                return 'overdue';
            }

            return $this->status->value;
        });
    }

    protected function pendingDays(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->invoice_date->diffInDays(
            $this->paid_at ?? now(),
        ));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(InvoiceSignature::class);
    }
}
