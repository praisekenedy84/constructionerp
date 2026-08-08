<?php

namespace App\Services;

use App\Enums\ComplianceAllocationLevel;
use App\Enums\PhaseStatus;
use App\Enums\RetentionStatus;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Models\ProjectPhase;
use Illuminate\Support\Facades\DB;

class PhaseService
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly SaleService $saleService,
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

    public function close(ProjectPhase $phase): ProjectPhase
    {
        return DB::transaction(function () use ($phase) {
            $phase = ProjectPhase::lockForUpdate()->findOrFail($phase->id);

            if ($phase->status === PhaseStatus::Closed) {
                throw new \InvalidArgumentException('This phase is already closed.');
            }

            $phase->update([
                'status' => PhaseStatus::Closed,
            ]);

            $this->saleService->ensureForPhase($phase);

            return $phase->fresh();
        });
    }

    public function releaseRetention(ProjectPhase $phase): ProjectPhase
    {
        return DB::transaction(function () use ($phase) {
            $phase = ProjectPhase::lockForUpdate()->findOrFail($phase->id);
            if (bccomp((string) $phase->retention_held_amount, '0', 2) <= 0) {
                throw new \InvalidArgumentException('This phase has no held retention to release.');
            }

            $phase->update([
                'retention_released_amount' => bcadd(
                    (string) $phase->retention_released_amount,
                    (string) $phase->retention_held_amount,
                    2
                ),
                'retention_held_amount' => '0.00',
                'retention_status' => RetentionStatus::Released,
                'retention_released_at' => now(),
                'status' => PhaseStatus::Succeeded,
            ]);

            $this->recalculatePhaseBudget($phase->fresh());
            $this->budgetService->syncProjectNetBudget($phase->project);

            return $phase->fresh();
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
        $otherDeductions = bcsub($totalDeductions, bcadd($retentionHeld, $retentionReleased, 2), 2);
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
