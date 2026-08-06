<?php

namespace App\Services;

use App\Enums\ComplianceAllocationLevel;
use App\Enums\ComplianceCalculationType;
use App\Enums\ComplianceItemEventType;
use App\Models\ComplianceRule;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Models\ProjectComplianceItemEvent;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectComplianceService
{
    public function __construct(
        private readonly ValuationService $valuationService,
        private readonly BudgetService $budgetService,
    ) {}

    /**
     * Attach compliance obligations at contract level (no phase required).
     *
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $items
     * @return Collection<int, ProjectComplianceItem>
     */
    public function attachToContract(Project $project, array $items, User $actor): Collection
    {
        return DB::transaction(function () use ($project, $items, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($project->phases()->exists()) {
                throw new \InvalidArgumentException(
                    'Contract-level compliance can only be added before phases are initiated. Add compliance on the phase via IPCs instead.'
                );
            }

            $contractBase = bcadd((string) $project->contract_amount, '0', 2);
            $created = collect();

            foreach ($this->normalizeItems($items) as $item) {
                $ruleId = (int) $item['compliance_rule_id'];
                if (ProjectComplianceItem::query()
                    ->where('project_id', $project->id)
                    ->where('compliance_rule_id', $ruleId)
                    ->exists()
                ) {
                    throw new \InvalidArgumentException(
                        'This compliance rule is already attached to the project.'
                    );
                }

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
                    $contractBase,
                    $item['rate'] ?? null,
                    $item['fixed_amount'] ?? null,
                );

                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }

                $row = ProjectComplianceItem::create([
                    'project_id' => $project->id,
                    'compliance_rule_id' => $rule->id,
                    'calculation_type' => $type,
                    'rate' => $type === ComplianceCalculationType::RatePercent
                        ? bcadd((string) $item['rate'], '0', 4)
                        : null,
                    'fixed_amount' => $type === ComplianceCalculationType::FixedAmount
                        ? bcadd((string) $item['fixed_amount'], '0', 2)
                        : null,
                    'amount' => $amount,
                    'allocation_level' => ComplianceAllocationLevel::Contract,
                    'phase_id' => null,
                    'valuation_id' => null,
                    'attached_at' => now(),
                    'migrated_at' => null,
                    'created_by' => $actor->id,
                ]);

                $this->recordEvent($row, ComplianceItemEventType::AttachedToContract, $actor, [
                    'contract_amount' => $contractBase,
                    'amount' => $amount,
                    'rule_name' => $rule->name,
                ]);

                $created->push($row);
            }

            $this->budgetService->syncProjectNetBudget($project->fresh());

            return $created;
        });
    }

    public function detachFromContract(Project $project, ProjectComplianceItem $item): void
    {
        DB::transaction(function () use ($project, $item) {
            $item = ProjectComplianceItem::lockForUpdate()->findOrFail($item->id);

            if ((int) $item->project_id !== (int) $project->id) {
                throw new \InvalidArgumentException('Compliance item does not belong to this project.');
            }

            if ($item->allocation_level !== ComplianceAllocationLevel::Contract) {
                throw new \InvalidArgumentException(
                    'Only contract-level compliance can be removed here. Phase compliance is managed via IPCs.'
                );
            }

            $item->delete();
            $this->budgetService->syncProjectNetBudget($project->fresh());
        });
    }

    /**
     * Move all contract-level compliance onto a phase as a single IPC (same amounts, no recalculation).
     * Called when Phase One is initiated.
     */
    public function migrateContractItemsToPhase(Project $project, ProjectPhase $phase, User $actor): ?\App\Models\Valuation
    {
        return DB::transaction(function () use ($project, $phase, $actor) {
            if ((int) $phase->project_id !== (int) $project->id) {
                throw new \InvalidArgumentException('Phase does not belong to this project.');
            }

            $items = ProjectComplianceItem::query()
                ->where('project_id', $project->id)
                ->onContract()
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                return null;
            }

            // Preserve already-calculated amounts: move as fixed values so Phase base does not rescale them.
            $payload = $items->map(fn (ProjectComplianceItem $item) => [
                'compliance_rule_id' => (int) $item->compliance_rule_id,
                'calculation_type' => ComplianceCalculationType::FixedAmount->value,
                'fixed_amount' => (string) $item->amount,
            ])->values()->all();

            $valuation = $this->valuationService->create($project, $phase, $payload, $actor);

            foreach ($items as $item) {
                $item->update([
                    'allocation_level' => ComplianceAllocationLevel::Phase,
                    'phase_id' => $phase->id,
                    'valuation_id' => $valuation->id,
                    'migrated_at' => now(),
                ]);

                $this->recordEvent($item->fresh(), ComplianceItemEventType::MigratedToPhase, $actor, [
                    'phase_id' => $phase->id,
                    'phase_sequence_no' => $phase->sequence_no,
                    'phase_name' => $phase->name,
                    'valuation_id' => $valuation->id,
                    'amount' => (string) $item->amount,
                ], $phase->id, $valuation->id);
            }

            return $valuation->fresh(['deductions']);
        });
    }

    /**
     * Recalculate rate-% amounts still on contract when contract value changes.
     */
    public function recalculateContractItems(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $contractBase = bcadd((string) $project->contract_amount, '0', 2);

            foreach (
                ProjectComplianceItem::query()
                    ->where('project_id', $project->id)
                    ->onContract()
                    ->lockForUpdate()
                    ->get() as $item
            ) {
                if ($item->calculation_type !== ComplianceCalculationType::RatePercent) {
                    continue;
                }

                $amount = $this->calculateAmount(
                    ComplianceCalculationType::RatePercent,
                    $contractBase,
                    $item->rate,
                    null,
                );
                $item->update(['amount' => $amount]);
            }

            $this->budgetService->syncProjectNetBudget($project->fresh());
        });
    }

    /**
     * @return array{
     *     contract_amount: string,
     *     compliance_total: string,
     *     remaining_contract_value: string,
     *     phase_allocated: string,
     *     unallocated_contract_value: string,
     *     has_phases: bool
     * }
     */
    public function contractSummary(Project $project): array
    {
        $contract = bcadd((string) $project->contract_amount, '0', 2);
        $complianceTotal = bcadd(
            (string) ProjectComplianceItem::query()
                ->where('project_id', $project->id)
                ->onContract()
                ->sum('amount'),
            '0',
            2,
        );
        $remaining = bcsub($contract, $complianceTotal, 2);
        if (bccomp($remaining, '0', 2) === -1) {
            $remaining = '0.00';
        }

        $phaseAllocated = bcadd((string) $project->phases()->sum('disbursed_amount'), '0', 2);
        $unallocated = bcsub($contract, $phaseAllocated, 2);
        if (bccomp($unallocated, '0', 2) === -1) {
            $unallocated = '0.00';
        }

        return [
            'contract_amount' => $contract,
            'compliance_total' => $complianceTotal,
            'remaining_contract_value' => $remaining,
            'phase_allocated' => $phaseAllocated,
            'unallocated_contract_value' => $unallocated,
            'has_phases' => $project->phases()->exists(),
        ];
    }

    public function contractComplianceTotal(Project $project): string
    {
        return bcadd(
            (string) ProjectComplianceItem::query()
                ->where('project_id', $project->id)
                ->onContract()
                ->sum('amount'),
            '0',
            2,
        );
    }

    /**
     * @param  array<int, array{compliance_rule_id?: mixed, calculation_type?: mixed, rate?: mixed, fixed_amount?: mixed}>  $items
     * @return array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>
     */
    private function normalizeItems(array $items): array
    {
        $seen = [];
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ruleId = (int) ($item['compliance_rule_id'] ?? 0);
            if ($ruleId <= 0 || isset($seen[$ruleId])) {
                continue;
            }
            $seen[$ruleId] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function calculateAmount(
        ComplianceCalculationType $type,
        string $baseAmount,
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

        return bcmul($baseAmount, bcdiv((string) $rate, '100', 6), 2);
    }

    private function recordEvent(
        ProjectComplianceItem $item,
        ComplianceItemEventType $type,
        User $actor,
        array $meta = [],
        ?int $phaseId = null,
        ?int $valuationId = null,
    ): void {
        ProjectComplianceItemEvent::create([
            'project_compliance_item_id' => $item->id,
            'event_type' => $type,
            'phase_id' => $phaseId,
            'valuation_id' => $valuationId,
            'meta' => $meta,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
