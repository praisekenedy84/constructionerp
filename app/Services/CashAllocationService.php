<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\Project;
use App\Models\User;
use App\Support\OrganizationFundUse;
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

    /**
     * Organization (general) cash wallet summary and use breakdown.
     *
     * @return array{
     *     summary: array{
     *         pending_count: int,
     *         pending_amount: string,
     *         received: string,
     *         utilized: string,
     *         cash_on_hand: string,
     *         disbursed: string,
     *     },
     *     use_breakdown: list<array{bucket: string, label: string, amount: string}>,
     * }
     */
    public function organizationOverview(): array
    {
        $position = $this->reportService->cashPosition(['scope' => 'organization']);

        $pending = CashAllocation::query()
            ->whereNull('project_id')
            ->where('status', CashAllocationStatus::Pending)
            ->get(['requested_amount']);

        $pendingAmount = '0.00';
        foreach ($pending as $row) {
            $pendingAmount = bcadd($pendingAmount, (string) $row->requested_amount, 2);
        }

        $allocations = CashAllocation::query()
            ->whereNull('project_id')
            ->with([
                'disbursements.expense:id,category,sub_type,description,expense_date,activity_ref',
            ])
            ->get(['id', 'opening_utilized_amount']);

        $useTotals = [
            'payroll' => '0.00',
            'office_stock' => '0.00',
            'event_inventory' => '0.00',
            'overhead' => '0.00',
            'opening' => '0.00',
        ];

        foreach ($allocations as $allocation) {
            $opening = bcadd((string) ($allocation->opening_utilized_amount ?? '0'), '0', 2);
            if (bccomp($opening, '0', 2) === 1) {
                $useTotals['opening'] = bcadd($useTotals['opening'], $opening, 2);
            }

            foreach ($allocation->disbursements as $disbursement) {
                $bucket = OrganizationFundUse::bucket($disbursement->expense?->sub_type);
                $useTotals[$bucket] = bcadd($useTotals[$bucket], (string) $disbursement->amount, 2);
            }
        }

        $useBreakdown = [];
        foreach ($useTotals as $bucket => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }
            $useBreakdown[] = [
                'bucket' => $bucket,
                'label' => OrganizationFundUse::bucketLabel($bucket),
                'amount' => $amount,
            ];
        }

        return [
            'summary' => [
                'pending_count' => $pending->count(),
                'pending_amount' => $pendingAmount,
                'received' => $position['received'],
                'utilized' => $position['utilized'],
                'cash_on_hand' => $position['cash_on_hand'],
                'disbursed' => $position['disbursed'],
            ],
            'use_breakdown' => $useBreakdown,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatOrganizationAllocation(CashAllocation $allocation): array
    {
        $allocation->loadMissing([
            'requester:id,name',
            'approver:id,name',
            'disbursements' => fn ($q) => $q->orderBy('disbursed_at')->orderBy('id'),
            'disbursements.expense:id,category,sub_type,description,expense_date,activity_ref',
            'disbursements.disburser:id,name',
        ]);

        $events = [];
        $events[] = [
            'type' => 'requested',
            'label' => 'Requested',
            'at' => $allocation->requested_at?->toIso8601String(),
            'amount' => (string) $allocation->requested_amount,
        ];

        if ($allocation->decided_at && in_array($allocation->status, [
            CashAllocationStatus::Received,
            CashAllocationStatus::Approved,
            CashAllocationStatus::Rejected,
        ], true)) {
            $events[] = [
                'type' => $allocation->status === CashAllocationStatus::Rejected ? 'rejected' : 'approved',
                'label' => $allocation->status === CashAllocationStatus::Rejected ? 'Rejected' : 'Approved',
                'at' => $allocation->decided_at?->toIso8601String(),
                'amount' => $allocation->status === CashAllocationStatus::Rejected
                    ? null
                    : (string) $allocation->received_amount,
            ];
        }

        if ($allocation->received_at && $allocation->status === CashAllocationStatus::Received) {
            $events[] = [
                'type' => 'received',
                'label' => 'Floated to organization cash on hand',
                'at' => $allocation->received_at?->toIso8601String(),
                'amount' => (string) $allocation->received_amount,
            ];
        }

        $opening = bcadd((string) ($allocation->opening_utilized_amount ?? '0'), '0', 2);
        if (bccomp($opening, '0', 2) === 1) {
            $events[] = [
                'type' => 'opening',
                'label' => OrganizationFundUse::bucketLabel('opening'),
                'at' => $allocation->received_at?->toIso8601String(),
                'amount' => $opening,
            ];
        }

        foreach ($allocation->disbursements as $disbursement) {
            $bucket = OrganizationFundUse::bucket($disbursement->expense?->sub_type);
            $events[] = [
                'type' => 'use',
                'label' => OrganizationFundUse::bucketLabel($bucket)
                    .($disbursement->expense?->sub_type ? " ({$disbursement->expense->sub_type})" : ''),
                'at' => $disbursement->disbursed_at?->toIso8601String(),
                'amount' => (string) $disbursement->amount,
                'description' => $disbursement->expense?->description,
                'payee' => $disbursement->payee,
                'reference_no' => $disbursement->reference_no,
            ];
        }

        return [
            'id' => $allocation->id,
            'status' => $allocation->status->value,
            'requested_amount' => (string) $allocation->requested_amount,
            'received_amount' => (string) $allocation->received_amount,
            'utilized_amount' => (string) $allocation->utilized_amount,
            'balance' => (string) $allocation->balance,
            'method' => $allocation->method,
            'reference_no' => $allocation->reference_no,
            'requested_at' => $allocation->requested_at?->toIso8601String(),
            'received_at' => $allocation->received_at?->toIso8601String(),
            'decided_at' => $allocation->decided_at?->toIso8601String(),
            'rejection_reason' => $allocation->rejection_reason,
            'requester' => $allocation->requester ? [
                'id' => $allocation->requester->id,
                'name' => $allocation->requester->name,
            ] : null,
            'approver' => $allocation->approver ? [
                'id' => $allocation->approver->id,
                'name' => $allocation->approver->name,
            ] : null,
            'lifecycle' => $events,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatOrganizationUse(\App\Models\CashDisbursement $disbursement): array
    {
        $disbursement->loadMissing([
            'expense:id,category,sub_type,description',
            'disburser:id,name',
        ]);

        $bucket = OrganizationFundUse::bucket($disbursement->expense?->sub_type);

        return [
            'id' => $disbursement->id,
            'allocation_id' => $disbursement->cash_allocation_id,
            'amount' => (string) $disbursement->amount,
            'method' => $disbursement->method,
            'payee' => $disbursement->payee,
            'reference_no' => $disbursement->reference_no,
            'disbursed_at' => $disbursement->disbursed_at?->toIso8601String(),
            'bucket' => $bucket,
            'bucket_label' => OrganizationFundUse::bucketLabel($bucket),
            'sub_type' => $disbursement->expense?->sub_type,
            'description' => $disbursement->expense?->description,
            'disburser' => $disbursement->disburser?->name,
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
