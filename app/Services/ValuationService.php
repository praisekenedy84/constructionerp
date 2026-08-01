<?php

namespace App\Services;

use App\Enums\ComplianceCalculationType;
use App\Enums\ValuationStatus;
use App\Models\ComplianceRule;
use App\Models\Project;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationDeduction;
use Illuminate\Support\Facades\DB;

class ValuationService
{
    /**
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $complianceItems
     */
    public function create(Project $project, array $complianceItems, User $creator): Valuation
    {
        return DB::transaction(function () use ($project, $complianceItems, $creator) {
            $certificateNo = (int) Valuation::where('project_id', $project->id)->max('certificate_no') + 1;
            $contract = bcadd((string) $project->contract_amount, '0', 2);

            $valuation = Valuation::create([
                'project_id' => $project->id,
                'certificate_no' => $certificateNo,
                'gross_value' => '0.00',
                'total_deductions' => '0.00',
                'net_value' => '0.00',
                'status' => ValuationStatus::Draft,
                'created_by' => $creator->id,
            ]);

            $this->syncComplianceItems($valuation, $contract, $complianceItems);
            $this->syncProjectNetBudget($project);

            return $valuation->fresh(['deductions']);
        });
    }

    /**
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $complianceItems
     */
    public function updateDraft(Valuation $valuation, array $complianceItems): Valuation
    {
        return DB::transaction(function () use ($valuation, $complianceItems) {
            $valuation = Valuation::lockForUpdate()->findOrFail($valuation->id);

            if ($valuation->status !== ValuationStatus::Draft) {
                throw new \InvalidArgumentException('Only draft IPCs can be updated.');
            }

            $project = $valuation->project;
            $contract = bcadd((string) $project->contract_amount, '0', 2);

            $valuation->deductions()->delete();
            $this->syncComplianceItems($valuation, $contract, $complianceItems);
            $this->syncProjectNetBudget($project);

            return $valuation->fresh(['deductions', 'project']);
        });
    }

    public function certify(Valuation $valuation, User $certifier): Valuation
    {
        return DB::transaction(function () use ($valuation, $certifier) {
            $valuation = Valuation::lockForUpdate()->findOrFail($valuation->id);

            if ($valuation->status === ValuationStatus::Certified) {
                throw new \InvalidArgumentException('Valuation is already certified.');
            }

            $valuation->update([
                'status' => ValuationStatus::Certified,
                'certified_by' => $certifier->id,
                'certified_at' => now(),
            ]);

            return $valuation->fresh(['deductions']);
        });
    }

    public function syncProjectNetBudget(Project $project): void
    {
        $project = Project::lockForUpdate()->findOrFail($project->id);
        $totalCompliance = bcadd((string) $project->valuations()->sum('total_deductions'), '0', 2);
        $remaining = bcsub(bcadd((string) $project->contract_amount, '0', 2), $totalCompliance, 2);

        $project->update([
            'net_budget' => bccomp($remaining, '0', 2) === -1 ? '0.00' : $remaining,
        ]);
    }

    /**
     * Recalculate every IPC's rate-% amounts from the current contract, then sync net budget.
     * Used when the project contract amount changes.
     */
    public function recalculateProjectIpcs(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $contract = bcadd((string) $project->contract_amount, '0', 2);

            foreach ($project->valuations()->with('deductions')->get() as $valuation) {
                $total = '0.00';

                foreach ($valuation->deductions as $deduction) {
                    $amount = $this->calculateAmount(
                        $deduction->calculation_type,
                        $contract,
                        $deduction->rate,
                        $deduction->fixed_amount,
                    );

                    $deduction->update(['amount' => $amount]);
                    $total = bcadd($total, $amount, 2);
                }

                $valuation->update([
                    'total_deductions' => $total,
                    'net_value' => $total,
                ]);
            }

            $this->syncProjectNetBudget($project);
        });
    }

    /**
     * @param  array<int, array{compliance_rule_id: int, calculation_type: string, rate?: mixed, fixed_amount?: mixed}>  $complianceItems
     */
    private function syncComplianceItems(Valuation $valuation, string $contractAmount, array $complianceItems): void
    {
        $totalDeductions = '0.00';
        $seenRuleIds = [];

        foreach ($complianceItems as $item) {
            $ruleId = (int) ($item['compliance_rule_id'] ?? 0);
            if ($ruleId <= 0 || isset($seenRuleIds[$ruleId])) {
                continue;
            }
            $seenRuleIds[$ruleId] = true;

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
                $contractAmount,
                $item['rate'] ?? null,
                $item['fixed_amount'] ?? null,
            );

            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            ValuationDeduction::create([
                'valuation_id' => $valuation->id,
                'compliance_rule_id' => $rule->id,
                'name' => $rule->name,
                'calculation_type' => $type->value,
                'rule_type' => null,
                'rate' => $type === ComplianceCalculationType::RatePercent
                    ? bcadd((string) $item['rate'], '0', 2)
                    : null,
                'fixed_amount' => $type === ComplianceCalculationType::FixedAmount
                    ? bcadd((string) $item['fixed_amount'], '0', 2)
                    : null,
                'amount' => $amount,
                'created_at' => now(),
            ]);

            $totalDeductions = bcadd($totalDeductions, $amount, 2);
        }

        // total_deductions = this IPC's compliance total; net_value mirrors it (no separate gross).
        $valuation->update([
            'gross_value' => '0.00',
            'total_deductions' => $totalDeductions,
            'net_value' => $totalDeductions,
        ]);
    }

    private function calculateAmount(
        ComplianceCalculationType $type,
        string $contractAmount,
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

        return bcmul($contractAmount, bcdiv((string) $rate, '100', 6), 2);
    }
}
