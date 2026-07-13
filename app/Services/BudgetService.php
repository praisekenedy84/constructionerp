<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Models\BudgetTransaction;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public function remainingBudget(Project $project): string
    {
        $spent = (string) BudgetTransaction::where('project_id', $project->id)->sum('amount');

        return bcsub((string) $project->net_budget, $spent, 2);
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
