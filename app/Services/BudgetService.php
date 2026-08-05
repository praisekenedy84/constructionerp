<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Models\BudgetTransaction;
use App\Models\Project;
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
     *     utilization_percentage: string
     * }
     */
    public function summary(Project $project): array
    {
        $grossBudget = bcadd((string) $project->phases()->sum('disbursed_amount'), '0', 2);
        $netBudget = bcadd((string) $project->net_budget, '0', 2);
        $ledgerSpend = bcadd(
            (string) BudgetTransaction::where('project_id', $project->id)->sum('amount'),
            '0',
            2,
        );
        $ipcDeductions = bcsub($grossBudget, $netBudget, 2);
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
