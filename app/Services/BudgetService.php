<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Enums\ComplianceAllocationLevel;
use App\Models\BudgetTransaction;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /**
     * @return array{
     *     gross_budget: string,
     *     ipc_deductions: string,
     *     ledger_spend: string,
     *     utilized_budget: string,
     *     remaining_budget: string,
     *     utilization_percentage: string,
     *     contract_amount: string,
     *     contract_compliance_total: string,
     *     remaining_contract_value: string,
     *     phase_allocated: string,
     *     unallocated_contract_value: string,
     *     has_phases: bool
     * }
     */
    public function summary(Project $project): array
    {
        $contractAmount = bcadd((string) $project->contract_amount, '0', 2);
        $contractCompliance = bcadd(
            (string) ProjectComplianceItem::query()
                ->where('project_id', $project->id)
                ->where('allocation_level', ComplianceAllocationLevel::Contract)
                ->sum('amount'),
            '0',
            2,
        );
        $remainingContract = bcsub($contractAmount, $contractCompliance, 2);
        if (bccomp($remainingContract, '0', 2) === -1) {
            $remainingContract = '0.00';
        }

        $phaseAllocated = bcadd((string) $project->phases()->sum('disbursed_amount'), '0', 2);
        $unallocatedContract = bcsub($contractAmount, $phaseAllocated, 2);
        if (bccomp($unallocatedContract, '0', 2) === -1) {
            $unallocatedContract = '0.00';
        }
        $hasPhases = $project->phases()->exists();

        // Before phases: gross tracks remaining contract value after compliance.
        $grossBudget = $hasPhases ? $phaseAllocated : $remainingContract;
        $netBudget = bcadd((string) $project->net_budget, '0', 2);
        $ledgerSpend = bcadd(
            (string) BudgetTransaction::where('project_id', $project->id)->sum('amount'),
            '0',
            2,
        );
        $ipcDeductions = $hasPhases
            ? bcsub($grossBudget, $netBudget, 2)
            : $contractCompliance;
        if (bccomp($ipcDeductions, '0', 2) === -1) {
            $ipcDeductions = '0.00';
        }

        $utilizedBudget = bcadd($ipcDeductions, $ledgerSpend, 2);
        $remainingBudget = bcsub($netBudget, $ledgerSpend, 2);

        return [
            'gross_budget' => $grossBudget,
            'ipc_deductions' => $ipcDeductions,
            'ledger_spend' => $ledgerSpend,
            'utilized_budget' => $utilizedBudget,
            'remaining_budget' => $remainingBudget,
            'utilization_percentage' => bccomp($grossBudget, '0', 2) === 0
                ? '0.00'
                : bcmul(bcdiv($utilizedBudget, $grossBudget, 6), '100', 2),
            'contract_amount' => $contractAmount,
            'contract_compliance_total' => $contractCompliance,
            'remaining_contract_value' => $remainingContract,
            'phase_allocated' => $phaseAllocated,
            'unallocated_contract_value' => $unallocatedContract,
            'has_phases' => $hasPhases,
        ];
    }

    public function ledgerSpend(Project $project): string
    {
        return $this->summary($project)['ledger_spend'];
    }

    public function remainingBudget(Project $project): string
    {
        return $this->summary($project)['remaining_budget'];
    }

    public function grossBudget(Project $project): string
    {
        return $this->summary($project)['gross_budget'];
    }

    public function ipcDeductions(Project $project): string
    {
        return $this->summary($project)['ipc_deductions'];
    }

    public function utilizedBudget(Project $project): string
    {
        return $this->summary($project)['utilized_budget'];
    }

    public function utilizationPercentage(Project $project): string
    {
        return $this->summary($project)['utilization_percentage'];
    }

    /**
     * Persist project.net_budget from the current financial layer.
     * Before phases: contract − contract-level compliance.
     * After phases: Σ phase_net_budget (compliance lives on phases/IPCs).
     */
    public function syncProjectNetBudget(Project $project): void
    {
        $project = Project::lockForUpdate()->findOrFail($project->id);

        if (! $project->phases()->exists()) {
            $compliance = bcadd(
                (string) ProjectComplianceItem::query()
                    ->where('project_id', $project->id)
                    ->where('allocation_level', ComplianceAllocationLevel::Contract)
                    ->sum('amount'),
                '0',
                2,
            );
            $net = bcsub(bcadd((string) $project->contract_amount, '0', 2), $compliance, 2);
            if (bccomp($net, '0', 2) === -1) {
                $net = '0.00';
            }
            $project->update(['net_budget' => $net]);

            return;
        }

        $net = bcadd((string) $project->phases()->sum('phase_net_budget'), '0', 2);
        $project->update(['net_budget' => $net]);
    }

    /**
     * @param  array{
     *     type: BudgetTransactionType|string,
     *     amount: string|int|float,
     *     boq_item_id?: int|null,
     *     reference_entity_type?: string|null,
     *     reference_entity_id?: int|null,
     *     reason?: string|null,
     *     created_by: int,
     * }  $data
     */
    public function createTransaction(int $projectId, array $data): BudgetTransaction
    {
        return DB::transaction(function () use ($projectId, $data) {
            $type = $data['type'] instanceof BudgetTransactionType
                ? $data['type']
                : BudgetTransactionType::from($data['type']);

            $amount = $this->normalizeAmount($data['amount']);

            if ($type === BudgetTransactionType::ManualAdjustment && empty($data['reason'])) {
                throw new \InvalidArgumentException('MANUAL_ADJUSTMENT requires a non-empty reason.');
            }

            return BudgetTransaction::create([
                'project_id' => $projectId,
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'type' => $type,
                'amount' => $amount,
                'reference_entity_type' => $data['reference_entity_type'] ?? null,
                'reference_entity_id' => $data['reference_entity_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'created_by' => $data['created_by'],
                'created_at' => now(),
            ]);
        });
    }

    private function normalizeAmount(string|int|float $amount): string
    {
        if (is_string($amount)) {
            return bcadd($amount, '0', 2);
        }

        return bcadd((string) $amount, '0', 2);
    }
}
