<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'client',
        'customer_id',
        'location',
        'contract_amount',
        'wht_percentage',
        'net_budget',
        'physical_progress_pct',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'contract_amount' => 'decimal:2',
            'wht_percentage' => 'decimal:2',
            'net_budget' => 'decimal:2',
            'physical_progress_pct' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => ProjectStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            // Prefer an explicitly provided net budget (e.g. after compliance charges).
            if (array_key_exists('net_budget', $project->getAttributes())
                && $project->getAttributes()['net_budget'] !== null) {
                return;
            }

            $wht = $project->wht_percentage ?? 0;
            $project->net_budget = bcmul(
                (string) $project->contract_amount,
                bcsub('1', bcdiv((string) $wht, '100', 4), 4),
                2
            );
        });
    }

    /**
     * Deduction for one charge: rate % of contract, fixed amount, or the lesser when both are set.
     */
    public static function chargeAmount(string $contractAmount, mixed $rate = null, mixed $fixedAmount = null): string
    {
        $contract = bcadd((string) $contractAmount, '0', 2);
        $rateValue = is_numeric($rate) ? (string) $rate : '0';
        $fixed = ($fixedAmount === null || $fixedAmount === '')
            ? '0'
            : bcadd((string) $fixedAmount, '0', 2);

        $fromPercent = '0.00';
        if (bccomp($rateValue, '0', 4) === 1) {
            $fromPercent = bcmul($contract, bcdiv($rateValue, '100', 6), 2);
        }

        $hasPercent = bccomp($fromPercent, '0', 2) === 1;
        $hasFixed = bccomp($fixed, '0', 2) === 1;

        if ($hasPercent && $hasFixed) {
            return bccomp($fromPercent, $fixed, 2) === 1 ? $fixed : $fromPercent;
        }

        if ($hasPercent) {
            return $fromPercent;
        }

        return $hasFixed ? $fixed : '0.00';
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $rules
     */
    public static function netBudgetFromCharges(string $contractAmount, iterable $rules): string
    {
        $totalCharges = '0.00';

        foreach ($rules as $rule) {
            $rate = data_get($rule, 'rate');
            $fixed = data_get($rule, 'max_amount');
            $totalCharges = bcadd(
                $totalCharges,
                self::chargeAmount($contractAmount, $rate, $fixed),
                2
            );
        }

        $remaining = bcsub(bcadd($contractAmount, '0', 2), $totalCharges, 2);

        return bccomp($remaining, '0', 2) === -1 ? '0.00' : $remaining;
    }

    public function withholdingTaxRates(): HasMany
    {
        return $this->hasMany(WithholdingTaxRate::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function complianceRules(): HasMany
    {
        return $this->hasMany(ProjectComplianceRule::class);
    }

    public function complianceItems(): HasMany
    {
        return $this->hasMany(ProjectComplianceItem::class);
    }

    public function boqSections(): HasMany
    {
        return $this->hasMany(BoqSection::class);
    }

    public function boqRevisions(): HasMany
    {
        return $this->hasMany(BoqRevision::class);
    }

    public function budgetTransactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function cashAllocations(): HasMany
    {
        return $this->hasMany(CashAllocation::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function stockLocations(): HasMany
    {
        return $this->hasMany(StockLocation::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Reference-only staff/recipients used on this project (many-to-many). */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(Recipient::class, 'project_recipient')
            ->withTimestamps()
            ->orderBy('recipients.name');
    }

    public function recipientAttendances(): HasMany
    {
        return $this->hasMany(RecipientAttendance::class);
    }

    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    public function equipmentAssignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Legacy single-sale accessor (first sale for the project). Prefer sales().
     */
    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    public function workflowConfigs(): HasMany
    {
        return $this->hasMany(WorkflowConfig::class);
    }
}
