<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashAllocationService
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly BudgetService $budgetService,
    ) {}

    public function request(?int $projectId, string $amount, User $requester, array $opts = []): CashAllocation
    {
        return DB::transaction(function () use ($projectId, $amount, $requester, $opts) {
            return CashAllocation::create([
                'project_id' => $projectId,
                'requested_amount' => bcadd($amount, '0', 2),
                'received_amount' => '0',
                'utilized_amount' => '0',
                'status' => CashAllocationStatus::Pending,
                'requested_by' => $requester->id,
                'method' => $opts['method'] ?? null,
                'reference_no' => $opts['reference_no'] ?? null,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Manager approval funds finance cash-in-hand and (for project requests)
     * deducts the amount from the project budget. Optional approved_amount amends
     * the requested figure before funding.
     *
     * @param  array{approved_amount?: string|null, method?: string|null, reference_no?: string|null}  $opts
     */
    public function approve(CashAllocation $allocation, User $approver, array $opts = []): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $approver, $opts) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Pending) {
                throw new \InvalidArgumentException('Only pending cash requests can be approved.');
            }

            $fundedAmount = isset($opts['approved_amount']) && $opts['approved_amount'] !== null && $opts['approved_amount'] !== ''
                ? bcadd((string) $opts['approved_amount'], '0', 2)
                : bcadd((string) $allocation->requested_amount, '0', 2);

            if (bccomp($fundedAmount, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Approved amount must be greater than zero.');
            }

            if ($allocation->project_id) {
                $project = Project::lockForUpdate()->findOrFail($allocation->project_id);
                $remaining = $this->budgetService->remainingBudget($project);

                if (bccomp($fundedAmount, $remaining, 2) === 1) {
                    throw new \InvalidArgumentException(
                        "Approved amount ({$fundedAmount}) exceeds remaining project budget ({$remaining})."
                    );
                }

                $this->budgetService->createTransaction($allocation->project_id, [
                    'type' => BudgetTransactionType::CashAllocation,
                    'amount' => $fundedAmount,
                    'reference_entity_type' => 'cash_allocation',
                    'reference_entity_id' => $allocation->id,
                    'reason' => bccomp($fundedAmount, (string) $allocation->requested_amount, 2) === 0
                        ? 'Fund request approved — cash floated to finance'
                        : 'Fund request approved/amended — cash floated to finance',
                    'created_by' => $approver->id,
                ]);
            }

            $allocation->update([
                'status' => CashAllocationStatus::Received,
                'requested_amount' => $fundedAmount,
                'received_amount' => $fundedAmount,
                'approved_by' => $approver->id,
                'decided_at' => now(),
                'received_at' => now(),
                'method' => $opts['method'] ?? $allocation->method,
                'reference_no' => $opts['reference_no'] ?? $allocation->reference_no,
            ]);

            return $allocation->fresh();
        });
    }

    public function reject(CashAllocation $allocation, User $approver, ?string $reason = null): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $approver, $reason) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Pending) {
                throw new \InvalidArgumentException('Only pending cash requests can be rejected.');
            }

            $allocation->update([
                'status' => CashAllocationStatus::Rejected,
                'approved_by' => $approver->id,
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $allocation->fresh();
        });
    }

    /**
     * Legacy path for allocations left in "approved" before auto-funding on approve.
     * Also used if finance records a physical receipt amount different from approved.
     *
     * @param  array{method?: string|null, reference_no?: string|null}  $opts
     */
    public function receive(CashAllocation $allocation, string $amount, User $actor, array $opts = []): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $amount, $actor, $opts) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Approved) {
                throw new \InvalidArgumentException('Only approved cash requests can be marked as received.');
            }

            $receivedAmount = bcadd($amount, '0', 2);

            if ($allocation->project_id && ! $this->hasBudgetTransaction($allocation)) {
                $project = Project::lockForUpdate()->findOrFail($allocation->project_id);
                $remaining = $this->budgetService->remainingBudget($project);

                if (bccomp($receivedAmount, $remaining, 2) === 1) {
                    throw new \InvalidArgumentException(
                        "Received amount ({$receivedAmount}) exceeds remaining project budget ({$remaining})."
                    );
                }

                $this->budgetService->createTransaction($allocation->project_id, [
                    'type' => BudgetTransactionType::CashAllocation,
                    'amount' => $receivedAmount,
                    'reference_entity_type' => 'cash_allocation',
                    'reference_entity_id' => $allocation->id,
                    'reason' => 'Fund request received — cash floated to finance',
                    'created_by' => $actor->id,
                ]);
            }

            $allocation->update([
                'status' => CashAllocationStatus::Received,
                'received_amount' => $receivedAmount,
                'received_at' => now(),
                'method' => $opts['method'] ?? $allocation->method,
                'reference_no' => $opts['reference_no'] ?? $allocation->reference_no,
            ]);

            return $allocation->fresh();
        });
    }

    /**
     * @return array{
     *     committed: string,
     *     disbursed: string,
     *     outstanding: string,
     *     cash_on_hand: string,
     * }
     */
    public function reconciliation(Project $project): array
    {
        $position = $this->reportService->cashPosition(['project_id' => $project->id]);

        return [
            'committed' => $position['committed'],
            'disbursed' => $position['disbursed'],
            'outstanding' => $position['outstanding'],
            'cash_on_hand' => $position['cash_on_hand'],
        ];
    }

    /**
     * @return array{
     *     reconciliation: array<string, string>,
     *     recent_allocations: \Illuminate\Database\Eloquent\Collection<int, CashAllocation>,
     * }
     */
    public function dashboard(Project $project): array
    {
        return [
            'reconciliation' => $this->reconciliation($project),
            'recent_allocations' => CashAllocation::query()
                ->where('project_id', $project->id)
                ->orderByDesc('requested_at')
                ->limit(10)
                ->get(),
        ];
    }

    private function hasBudgetTransaction(CashAllocation $allocation): bool
    {
        return BudgetTransaction::query()
            ->where('reference_entity_type', 'cash_allocation')
            ->where('reference_entity_id', $allocation->id)
            ->exists();
    }
}
