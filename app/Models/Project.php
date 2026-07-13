<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'client',
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
            $wht = $project->wht_percentage ?? 0;
            $project->net_budget = bcmul(
                (string) $project->contract_amount,
                bcsub('1', bcdiv((string) $wht, '100', 4), 4),
                2
            );
        });
    }

    public function withholdingTaxRates(): HasMany
    {
        return $this->hasMany(WithholdingTaxRate::class);
    }

    public function complianceRules(): HasMany
    {
        return $this->hasMany(ProjectComplianceRule::class);
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

    public function workflowConfigs(): HasMany
    {
        return $this->hasMany(WorkflowConfig::class);
    }
}
