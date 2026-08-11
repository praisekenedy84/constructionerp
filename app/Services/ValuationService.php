<?php

namespace App\Services;

use App\Enums\ComplianceCalculationType;
use App\Enums\ComplianceRuleType;
use App\Enums\ExpenseCategory;
use App\Enums\RetentionStatus;
use App\Enums\ValuationStatus;
use App\Models\ComplianceRule;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationDeduction;
use Illuminate\Support\Facades\DB;

class ValuationService
{
    public function __construct(
        private readonly ExpenseService $expenseService,
        private readonly PhaseService $phaseService,
        private readonly BudgetService $budgetService,
    ) {}

    /**
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $complianceItems
     */
    public function create(Project $project, ProjectPhase $phase, array $complianceItems, User $creator): Valuation
    {
        return DB::transaction(function () use ($project, $phase, $complianceItems, $creator) {
            $this->assertPhaseBelongsToProject($phase, $project);
            $certificateNo = (int) Valuation::where('phase_id', $phase->id)->max('certificate_no') + 1;
            $phaseBase = bcadd((string) $phase->disbursed_amount, '0', 2);

            $valuation = Valuation::create([
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'certificate_no' => $certificateNo,
                'gross_value' => '0.00',
                'total_deductions' => '0.00',
                'net_value' => '0.00',
                'status' => ValuationStatus::Draft,
                'created_by' => $creator->id,
            ]);

            $this->syncComplianceItems($valuation, $phase, $phaseBase, $complianceItems);
            $this->syncDirectExpenses($valuation->fresh(['deductions']), $creator->id);
            $this->phaseService->recalculatePhaseBudget($phase);
            $this->budgetService->syncProjectNetBudget($project);

            return $valuation->fresh(['deductions']);
        });
    }

    /**
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $complianceItems
     */
    public function updateDraft(Valuation $valuation, array $complianceItems): Valuation
    {
        return DB::transaction(function () use ($valuation, $complianceItems) {
            $valuation = Valuation::lockForUpdate()->findOrFail($valuation->id);

            if ($valuation->status !== ValuationStatus::Draft) {
                throw new \InvalidArgumentException('Only draft IPCs can be updated.');
            }

            $project = $valuation->project;
            $phase = $valuation->phase;
            $phaseBase = bcadd((string) $phase->disbursed_amount, '0', 2);

            $valuation->deductions()->delete();
            $this->syncComplianceItems($valuation, $phase, $phaseBase, $complianceItems);
            $this->syncDirectExpenses($valuation->fresh(['deductions']), (int) $valuation->created_by);
            $this->phaseService->recalculatePhaseBudget($phase);
            $this->budgetService->syncProjectNetBudget($project);

            return $valuation->fresh(['deductions', 'project']);
        });
    }

    public function certify(Valuation $valuation, User $certifier): Valuation
    {
        return DB::transaction(function () use ($valuation, $certifier) {
            $valuation = Valuation::lockForUpdate()->findOrFail($valuation->id);

            if ($valuation->status === ValuationStatus::Certified) {
                throw new \InvalidArgumentException('Valuation is already certified.');
            }

            $valuation->update([
                'status' => ValuationStatus::Certified,
                'certified_by' => $certifier->id,
                'certified_at' => now(),
            ]);

            return $valuation->fresh(['deductions']);
        });
    }

    public function deleteDraft(Valuation $valuation): void
    {
        DB::transaction(function () use ($valuation) {
            $valuation = Valuation::lockForUpdate()->findOrFail($valuation->id);

            if ($valuation->status !== ValuationStatus::Draft) {
                throw new \InvalidArgumentException('Only draft IPCs can be deleted.');
            }

            $project = $valuation->project;
            $this->removeDirectExpenses($valuation);
            $valuation->delete();
            $this->phaseService->recalculatePhaseBudget($valuation->phase);
            $this->budgetService->syncProjectNetBudget($project);
        });
    }

    public function syncProjectNetBudget(Project $project): void
    {
        $this->budgetService->syncProjectNetBudget($project);
    }

    /**
     * Recalculate every IPC's rate-% amounts from the current contract, then sync net budget.
     * Used when the project contract amount changes.
     */
    public function recalculateProjectIpcs(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            foreach ($project->valuations()->with('deductions')->get() as $valuation) {
                $phaseBase = bcadd((string) $valuation->phase->disbursed_amount, '0', 2);
                $total = '0.00';

                foreach ($valuation->deductions as $deduction) {
                    $amount = $this->calculateAmount(
                        $deduction->calculation_type,
                        $phaseBase,
                        $deduction->rate,
                        $deduction->fixed_amount,
                    );

                    $deduction->update(['amount' => $amount]);
                    $total = bcadd($total, $amount, 2);
                }

                $valuation->update([
                    'total_deductions' => $total,
                    'net_value' => $total,
                ]);

                $this->syncDirectExpenses($valuation->fresh(['deductions']), (int) $valuation->created_by);
                $this->phaseService->recalculatePhaseBudget($valuation->phase);
            }

            $this->budgetService->syncProjectNetBudget($project);
        });
    }

    /**
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $complianceItems
     */
    private function syncComplianceItems(
        Valuation $valuation,
        ProjectPhase $phase,
        string $phaseAmount,
        array $complianceItems
    ): void {
        $totalDeductions = '0.00';
        $seenRuleIds = [];
        $retentionHeld = '0.00';

        foreach ($complianceItems as $item) {
            $ruleId = (int) ($item['compliance_rule_id'] ?? 0);
            if ($ruleId <= 0 || isset($seenRuleIds[$ruleId])) {
                continue;
            }
            $seenRuleIds[$ruleId] = true;

            $rule = ComplianceRule::query()
                ->whereKey($ruleId)
                ->whereNull('deleted_at')
                ->first();
            if (! $rule) {
                continue;
            }

            $type = ComplianceCalculationType::from($item['calculation_type']);
            $amount = $this->calculateAmount(
                $type,
                $phaseAmount,
                $item['rate'] ?? null,
                $item['fixed_amount'] ?? null,
            );

            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            ValuationDeduction::create([
                'valuation_id' => $valuation->id,
                'compliance_rule_id' => $rule->id,
                'name' => $rule->name,
                'calculation_type' => $type->value,
                'rule_type' => $rule->rule_type?->value,
                'rate' => $type === ComplianceCalculationType::RatePercent
                    ? bcadd((string) $item['rate'], '0', 2)
                    : null,
                'fixed_amount' => $type === ComplianceCalculationType::FixedAmount
                    ? bcadd((string) $item['fixed_amount'], '0', 2)
                    : null,
                'amount' => $amount,
                'created_at' => now(),
            ]);

            $totalDeductions = bcadd($totalDeductions, $amount, 2);

            if ($rule->rule_type === ComplianceRuleType::Retention) {
                $retentionHeld = bcadd($retentionHeld, $amount, 2);
            }
        }

        $phase->update([
            'retention_held_amount' => $retentionHeld,
            'retention_released_amount' => '0.00',
            'retention_receivable_amount' => '0.00',
            'retention_forfeited_amount' => '0.00',
            'retention_released_at' => null,
            'retention_forfeited_at' => null,
            'retention_status' => bccomp($retentionHeld, '0', 2) === 1
                ? RetentionStatus::Held
                : RetentionStatus::None,
        ]);

        // total_deductions = this IPC's compliance total; net_value mirrors it (no separate gross).
        $valuation->update([
            'gross_value' => $phaseAmount,
            'total_deductions' => $totalDeductions,
            'net_value' => $totalDeductions,
        ]);
    }

    /**
     * Mirror IPC compliance lines as accounting-only direct expenses (no cash disbursement).
     * Contract deductions reduce net_budget separately; these rows identify the cost in the ledger.
     */
    private function syncDirectExpenses(Valuation $valuation, int $recordedBy): void
    {
        $this->removeDirectExpenses($valuation);

        $ipcRef = 'IPC-'.$valuation->certificate_no;
        $expenseDate = optional($valuation->created_at)?->toDateString() ?? now()->toDateString();

        foreach ($valuation->deductions as $deduction) {
            if (bccomp((string) $deduction->amount, '0', 2) <= 0) {
                continue;
            }

            // Retention is a hold → later cash release / receivable, not a project expense.
            $ruleType = $deduction->rule_type;
            if ($ruleType === ComplianceRuleType::Retention->value
                || $ruleType === ComplianceRuleType::Retention
            ) {
                continue;
            }

            $this->expenseService->create([
                'project_id' => $valuation->project_id,
                'valuation_id' => $valuation->id,
                'category' => ExpenseCategory::Direct,
                'sub_type' => $deduction->name,
                'activity_ref' => $ipcRef,
                'amount' => $deduction->amount,
                'description' => "{$ipcRef} compliance — {$deduction->name}",
                'expense_date' => $expenseDate,
                'recorded_by' => $recordedBy,
            ]);
        }
    }

    private function removeDirectExpenses(Valuation $valuation): void
    {
        Expense::query()
            ->where('valuation_id', $valuation->id)
            ->get()
            ->each
            ->delete();
    }

    private function calculateAmount(
        ComplianceCalculationType $type,
        string $contractAmount,
        mixed $rate,
        mixed $fixedAmount,
    ): string {
        if ($type === ComplianceCalculationType::FixedAmount) {
            if ($fixedAmount === null || $fixedAmount === '') {
                return '0.00';
            }

            return bcadd((string) $fixedAmount, '0', 2);
        }

        if ($rate === null || $rate === '' || bccomp((string) $rate, '0', 4) <= 0) {
            return '0.00';
        }

        return bcmul($contractAmount, bcdiv((string) $rate, '100', 6), 2);
    }

    private function assertPhaseBelongsToProject(ProjectPhase $phase, Project $project): void
    {
        if ((int) $phase->project_id !== (int) $project->id) {
            throw new \InvalidArgumentException('The selected phase does not belong to this project.');
        }
    }
}
