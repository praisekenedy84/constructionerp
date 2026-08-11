<?php

namespace App\Services;

use App\Enums\ComplianceAllocationLevel;
use App\Enums\DepositSource;
use App\Enums\MoneyAccountType;
use App\Enums\PhaseStatus;
use App\Enums\RetentionStatus;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PhaseService
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly SaleService $saleService,
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    public function create(Project $project, array $attributes): ProjectPhase
    {
        return DB::transaction(function () use ($project, $attributes) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            $disbursed = bcadd((string) ($attributes['disbursed_amount'] ?? '0'), '0', 2);
            if (bccomp($disbursed, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Disbursed amount must be greater than zero.');
            }

            $totalDisbursed = bcadd((string) $project->phases()->sum('disbursed_amount'), '0', 2);
            $newTotal = bcadd($totalDisbursed, $disbursed, 2);
            if (bccomp($newTotal, (string) $project->contract_amount, 2) === 1) {
                throw new \InvalidArgumentException('Total phase disbursements cannot exceed contract amount.');
            }

            $sequence = (int) $project->phases()->max('sequence_no') + 1;

            // Phase 1 absorbs existing contract compliance — disbursement must cover those obligations
            // (compliance is deducted from the phase, not from the allocation ceiling).
            if ($sequence === 1) {
                $contractCompliance = bcadd(
                    (string) ProjectComplianceItem::query()
                        ->where('project_id', $project->id)
                        ->where('allocation_level', ComplianceAllocationLevel::Contract)
                        ->sum('amount'),
                    '0',
                    2,
                );
                if (bccomp($contractCompliance, '0', 2) === 1
                    && bccomp($disbursed, $contractCompliance, 2) === -1
                ) {
                    throw new \InvalidArgumentException(
                        "Phase 1 disbursement must be at least {$contractCompliance} to cover contract compliance obligations that will move to this phase."
                    );
                }
            }

            $phase = ProjectPhase::create([
                'project_id' => $project->id,
                'sequence_no' => $sequence,
                'name' => $attributes['name'] ?? 'Phase '.$sequence,
                'status' => $attributes['status'] ?? PhaseStatus::Pending,
                'disbursed_amount' => $disbursed,
                'retention_held_amount' => '0.00',
                'retention_released_amount' => '0.00',
                'retention_receivable_amount' => '0.00',
                'retention_forfeited_amount' => '0.00',
                'other_deductions_amount' => '0.00',
                'phase_net_budget' => $disbursed,
                'retention_status' => RetentionStatus::None,
            ]);

            $this->budgetService->syncProjectNetBudget($project);
            $this->saleService->ensureForPhase($phase);

            return $phase->fresh();
        });
    }

    public function close(ProjectPhase $phase, ?User $actor = null): ProjectPhase
    {
        return DB::transaction(function () use ($phase, $actor) {
            $phase = ProjectPhase::lockForUpdate()->findOrFail($phase->id);

            if ($phase->status === PhaseStatus::Closed) {
                throw new \InvalidArgumentException('This phase is already closed.');
            }

            $phase->update([
                'status' => PhaseStatus::Closed,
            ]);

            $sale = $this->saleService->ensureForPhase($phase);

            // Convert surplus after absorbing pending deficit, or carry deficit to later phases.
            if ($actor !== null && ! $sale->isConverted()) {
                $this->saleService->applyPhaseCloseReceivable($sale, $actor);
            }

            return $phase->fresh();
        });
    }

    /**
     * Release all held retention across the project in one 50/50 split of the
     * cumulative held total: half deposits to a company account and returns to
     * phase budgets; half becomes a collectible retention receivable.
     */
    public function releaseProjectRetention(Project $project, MoneyAccount $account, User $actor): Project
    {
        return DB::transaction(function () use ($project, $account, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            $phases = ProjectPhase::query()
                ->where('project_id', $project->id)
                ->where('retention_held_amount', '>', 0)
                ->orderBy('sequence_no')
                ->lockForUpdate()
                ->get();

            if ($phases->isEmpty()) {
                throw new \InvalidArgumentException('This project has no held retention to release.');
            }

            $account = MoneyAccount::lockForUpdate()->findOrFail($account->id);
            if ($account->type !== MoneyAccountType::Manager || ! $account->is_active) {
                throw new \InvalidArgumentException('Retention must be deposited into an active company account.');
            }

            $totalHeld = '0.00';
            foreach ($phases as $phase) {
                $totalHeld = bcadd($totalHeld, (string) $phase->retention_held_amount, 2);
            }

            // One cumulative 50/50 split; odd cent goes to the receivable half.
            $releasedTotal = bcdiv($totalHeld, '2', 2);
            $receivableTotal = bcsub($totalHeld, $releasedTotal, 2);

            $releasedAllocated = '0.00';
            $phaseCount = $phases->count();

            foreach ($phases as $index => $phase) {
                $held = bcadd((string) $phase->retention_held_amount, '0', 2);

                if ($index === $phaseCount - 1) {
                    $phaseReleased = bcsub($releasedTotal, $releasedAllocated, 2);
                } else {
                    $phaseReleased = bcmul(bcdiv($held, $totalHeld, 8), $releasedTotal, 2);
                    $releasedAllocated = bcadd($releasedAllocated, $phaseReleased, 2);
                }

                $phaseReceivable = bcsub($held, $phaseReleased, 2);

                $phase->update([
                    'retention_released_amount' => bcadd((string) $phase->retention_released_amount, $phaseReleased, 2),
                    'retention_receivable_amount' => bcadd(
                        (string) ($phase->retention_receivable_amount ?? '0'),
                        $phaseReceivable,
                        2
                    ),
                    'retention_held_amount' => '0.00',
                    'retention_status' => RetentionStatus::Released,
                    'retention_released_at' => now(),
                    'status' => PhaseStatus::Succeeded,
                ]);

                $this->recalculatePhaseBudget($phase->fresh());
            }

            if (bccomp($releasedTotal, '0', 2) === 1) {
                $this->moneyAccountService->deposit($account, $releasedTotal, $actor, [
                    'deposit_source' => DepositSource::RetentionRelease,
                    'description' => "Retention release — {$project->code}: cumulative across {$phaseCount} phase(s)",
                    'reference_entity_type' => 'project',
                    'reference_entity_id' => $project->id,
                ]);
            }

            if (bccomp($receivableTotal, '0', 2) === 1) {
                $this->saleService->createRetentionReceivable($project, $receivableTotal, $actor);
            }

            $this->budgetService->syncProjectNetBudget($project->fresh());

            return $project->fresh();
        });
    }

    public function forfeitRetention(ProjectPhase $phase): ProjectPhase
    {
        return DB::transaction(function () use ($phase) {
            $phase = ProjectPhase::lockForUpdate()->findOrFail($phase->id);
            if (bccomp((string) $phase->retention_held_amount, '0', 2) <= 0) {
                throw new \InvalidArgumentException('This phase has no held retention to forfeit.');
            }

            $phase->update([
                'retention_forfeited_amount' => bcadd(
                    (string) $phase->retention_forfeited_amount,
                    (string) $phase->retention_held_amount,
                    2
                ),
                'retention_held_amount' => '0.00',
                'retention_status' => RetentionStatus::Forfeited,
                'retention_forfeited_at' => now(),
                'status' => PhaseStatus::Unsatisfactory,
            ]);

            $this->recalculatePhaseBudget($phase->fresh());
            $this->budgetService->syncProjectNetBudget($phase->project);

            return $phase->fresh();
        });
    }

    public function recalculatePhaseBudget(ProjectPhase $phase): void
    {
        $phase = ProjectPhase::lockForUpdate()->findOrFail($phase->id);
        $totalDeductions = bcadd((string) $phase->valuations()->sum('total_deductions'), '0', 2);
        $retentionHeld = bcadd((string) $phase->retention_held_amount, '0', 2);
        $retentionReleased = bcadd((string) $phase->retention_released_amount, '0', 2);
        $retentionReceivable = bcadd((string) ($phase->retention_receivable_amount ?? '0'), '0', 2);
        $retentionReturned = bcadd($retentionReleased, $retentionReceivable, 2);
        $otherDeductions = bcsub($totalDeductions, bcadd($retentionHeld, $retentionReturned, 2), 2);
        if (bccomp($otherDeductions, '0', 2) === -1) {
            $otherDeductions = '0.00';
        }

        $phaseNet = bcsub(
            bcsub(
                bcadd((string) $phase->disbursed_amount, '0', 2),
                $retentionHeld,
                2
            ),
            $otherDeductions,
            2
        );

        if (bccomp($phaseNet, '0', 2) === -1) {
            $phaseNet = '0.00';
        }

        $phase->update([
            'other_deductions_amount' => $otherDeductions,
            'phase_net_budget' => $phaseNet,
            'retention_status' => bccomp($retentionHeld, '0', 2) === 1
                ? RetentionStatus::Held
                : $phase->retention_status,
        ]);
    }
}
