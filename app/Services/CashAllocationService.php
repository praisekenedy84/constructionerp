<?php

namespace App\Services;

use App\Enums\CashAllocationStatus;
use App\Models\CashAllocation;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\User;
use App\Support\OrganizationFundUse;
use Illuminate\Support\Facades\DB;

class CashAllocationService
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    public function request(string $amount, User $requester): CashAllocation
    {
        return DB::transaction(function () use ($amount, $requester) {
            $this->moneyAccountService->ensureFinanceAccount($requester);

            return CashAllocation::create([
                'project_id' => null,
                'requested_amount' => bcadd($amount, '0', 2),
                'received_amount' => '0',
                'utilized_amount' => '0',
                'status' => CashAllocationStatus::Pending,
                'requested_by' => $requester->id,
                'method' => null,
                'reference_no' => null,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Manager approval transfers cash from a manager account into the finance wallet.
     * Project budget is not charged here — costs hit the ledger when expenses / project
     * requisitions are recorded.
     *
     * @param  array{
     *     source_account_id: int,
     *     approved_amount?: string|null,
     *     method?: string|null,
     *     reference_no?: string|null
     * }  $opts
     */
    public function approve(CashAllocation $allocation, User $approver, array $opts = []): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $approver, $opts) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Pending) {
                throw new \InvalidArgumentException('Only pending cash requests can be approved.');
            }

            $sourceAccountId = (int) ($opts['source_account_id'] ?? 0);
            if ($sourceAccountId < 1) {
                throw new \InvalidArgumentException('Select a manager account to fund this request from.');
            }

            $fundedAmount = isset($opts['approved_amount']) && $opts['approved_amount'] !== null && $opts['approved_amount'] !== ''
                ? bcadd((string) $opts['approved_amount'], '0', 2)
                : bcadd((string) $allocation->requested_amount, '0', 2);

            if (bccomp($fundedAmount, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Approved amount must be greater than zero.');
            }

            $source = MoneyAccount::findOrFail($sourceAccountId);

            $this->moneyAccountService->transferToFinance(
                $source,
                $fundedAmount,
                $approver,
                $allocation,
                [
                    'method' => $opts['method'] ?? $allocation->method,
                    'reference_no' => $opts['reference_no'] ?? $allocation->reference_no,
                ],
            );

            $allocation->update([
                'status' => CashAllocationStatus::Received,
                'source_account_id' => $source->id,
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
     *
     * @param  array{source_account_id?: int, method?: string|null, reference_no?: string|null}  $opts
     */
    public function receive(CashAllocation $allocation, string $amount, User $actor, array $opts = []): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $amount, $actor, $opts) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Approved) {
                throw new \InvalidArgumentException('Only approved cash requests can be marked as received.');
            }

            $receivedAmount = bcadd($amount, '0', 2);
            $sourceAccountId = (int) ($opts['source_account_id'] ?? $allocation->source_account_id ?? 0);
            if ($sourceAccountId < 1) {
                throw new \InvalidArgumentException('Select a manager account to fund this receipt from.');
            }

            $source = MoneyAccount::findOrFail($sourceAccountId);

            $this->moneyAccountService->transferToFinance(
                $source,
                $receivedAmount,
                $actor,
                $allocation,
                [
                    'method' => $opts['method'] ?? $allocation->method,
                    'reference_no' => $opts['reference_no'] ?? $allocation->reference_no,
                ],
            );

            $allocation->update([
                'status' => CashAllocationStatus::Received,
                'source_account_id' => $source->id,
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
     * Finance wallet summary (replaces scoped administrative cash overview).
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
        $position = $this->reportService->cashPosition([]);

        $pending = CashAllocation::query()
            ->where('status', CashAllocationStatus::Pending)
            ->get(['requested_amount']);

        $pendingAmount = '0.00';
        foreach ($pending as $row) {
            $pendingAmount = bcadd($pendingAmount, (string) $row->requested_amount, 2);
        }

        $finance = $this->moneyAccountService->ensureFinanceAccount();
        $disbursements = \App\Models\CashDisbursement::query()
            ->where('money_account_id', $finance->id)
            ->with('expense:id,category,sub_type,description')
            ->get();

        $useTotals = [
            'payroll' => '0.00',
            'office_stock' => '0.00',
            'event_inventory' => '0.00',
            'overhead' => '0.00',
            'opening' => '0.00',
            'project' => '0.00',
        ];

        foreach ($disbursements as $disbursement) {
            if ($disbursement->expense?->category?->value === 'direct'
                || ($disbursement->expense && $disbursement->expense->project_id)) {
                $useTotals['project'] = bcadd($useTotals['project'], (string) $disbursement->amount, 2);
                continue;
            }

            $bucket = OrganizationFundUse::bucket($disbursement->expense?->sub_type);
            $useTotals[$bucket] = bcadd($useTotals[$bucket], (string) $disbursement->amount, 2);
        }

        $useBreakdown = [];
        foreach ($useTotals as $bucket => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }
            $useBreakdown[] = [
                'bucket' => $bucket,
                'label' => $bucket === 'project'
                    ? 'Project expenses'
                    : OrganizationFundUse::bucketLabel($bucket),
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
            'sourceAccount:id,name',
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
                'label' => $allocation->sourceAccount
                    ? "Transferred from {$allocation->sourceAccount->name} to Finance Wallet"
                    : 'Floated to Finance Wallet',
                'at' => $allocation->received_at?->toIso8601String(),
                'amount' => (string) $allocation->received_amount,
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
            'source_account' => $allocation->sourceAccount ? [
                'id' => $allocation->sourceAccount->id,
                'name' => $allocation->sourceAccount->name,
            ] : null,
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
            'expense:id,category,sub_type,description,project_id',
            'disburser:id,name',
        ]);

        $isProject = (bool) $disbursement->expense?->project_id;
        $bucket = $isProject ? 'project' : OrganizationFundUse::bucket($disbursement->expense?->sub_type);

        return [
            'id' => $disbursement->id,
            'allocation_id' => $disbursement->cash_allocation_id,
            'amount' => (string) $disbursement->amount,
            'method' => $disbursement->method,
            'payee' => $disbursement->payee,
            'reference_no' => $disbursement->reference_no,
            'disbursed_at' => $disbursement->disbursed_at?->toIso8601String(),
            'bucket' => $bucket,
            'bucket_label' => $bucket === 'project'
                ? 'Project expenses'
                : OrganizationFundUse::bucketLabel($bucket),
            'sub_type' => $disbursement->expense?->sub_type,
            'description' => $disbursement->expense?->description,
            'disburser' => $disbursement->disburser?->name,
        ];
    }
}
