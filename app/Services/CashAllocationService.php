<?php

namespace App\Services;

use App\Enums\CashAllocationStatus;
use App\Models\CashAllocation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashAllocationService
{
    public function __construct(
        private readonly ReportService $reportService,
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

    public function approve(CashAllocation $allocation, User $approver): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $approver) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Pending) {
                throw new \InvalidArgumentException('Only pending cash requests can be approved.');
            }

            $allocation->update([
                'status' => CashAllocationStatus::Approved,
                'approved_by' => $approver->id,
                'decided_at' => now(),
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

    public function receive(CashAllocation $allocation, string $amount, array $opts = []): CashAllocation
    {
        return DB::transaction(function () use ($allocation, $amount, $opts) {
            $allocation = CashAllocation::lockForUpdate()->findOrFail($allocation->id);

            if ($allocation->status !== CashAllocationStatus::Approved) {
                throw new \InvalidArgumentException('Only approved cash requests can be marked as received.');
            }

            $receivedAmount = bcadd($amount, '0', 2);

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
}
