<?php

namespace App\Services;

use App\Enums\ComplianceRuleType;
use App\Enums\ValuationStatus;
use App\Models\Project;
use App\Models\Valuation;
use App\Models\ValuationDeduction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ValuationService
{
    /** @var array<int, ComplianceRuleType> */
    private const DEDUCTION_ORDER = [
        ComplianceRuleType::Retention,
        ComplianceRuleType::AdvanceRecovery,
        ComplianceRuleType::Wht,
        ComplianceRuleType::DefectLiability,
        ComplianceRuleType::MaterialTest,
        ComplianceRuleType::HivReport,
    ];

    public function create(Project $project, string $grossValue, User $creator): Valuation
    {
        return DB::transaction(function () use ($project, $grossValue, $creator) {
            $gross = bcadd($grossValue, '0', 2);
            $certificateNo = (int) Valuation::where('project_id', $project->id)->max('certificate_no') + 1;

            $valuation = Valuation::create([
                'project_id' => $project->id,
                'certificate_no' => $certificateNo,
                'gross_value' => $gross,
                'total_deductions' => '0',
                'net_value' => $gross,
                'status' => ValuationStatus::Draft,
                'created_by' => $creator->id,
            ]);

            $rules = $project->complianceRules()
                ->where('is_active', true)
                ->get()
                ->keyBy(fn ($rule) => $rule->rule_type->value);

            $remaining = $gross;
            $totalDeductions = '0';

            foreach (self::DEDUCTION_ORDER as $ruleType) {
                $rule = $rules->get($ruleType->value);

                if (! $rule) {
                    continue;
                }

                $amount = $this->calculateDeduction($project, $ruleType, $rule, $gross, $remaining);
                $amount = bcadd($amount, '0', 2);

                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }

                ValuationDeduction::create([
                    'valuation_id' => $valuation->id,
                    'rule_type' => $ruleType->value,
                    'rate' => (string) $rule->rate,
                    'amount' => $amount,
                    'created_at' => now(),
                ]);

                $totalDeductions = bcadd($totalDeductions, $amount, 2);
                $remaining = bcsub($remaining, $amount, 2);
            }

            $valuation->update([
                'total_deductions' => $totalDeductions,
                'net_value' => bcsub($gross, $totalDeductions, 2),
            ]);

            return $valuation->fresh(['deductions']);
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

    private function calculateDeduction(
        Project $project,
        ComplianceRuleType $ruleType,
        $rule,
        string $gross,
        string $remaining,
    ): string {
        if ($ruleType === ComplianceRuleType::AdvanceRecovery) {
            $priorRecovery = (string) ValuationDeduction::whereHas('valuation', function ($query) use ($project) {
                $query->where('project_id', $project->id);
            })
                ->where('rule_type', ComplianceRuleType::AdvanceRecovery->value)
                ->sum('amount');

            $calculated = bcmul($gross, bcdiv((string) $rule->rate, '100', 4), 2);

            if ($rule->max_amount !== null) {
                $maxRemaining = bcsub((string) $rule->max_amount, $priorRecovery, 2);

                if (bccomp($maxRemaining, '0', 2) <= 0) {
                    return '0';
                }

                return bccomp($calculated, $maxRemaining, 2) === 1 ? $maxRemaining : $calculated;
            }

            return $calculated;
        }

        return bcmul($gross, bcdiv((string) $rule->rate, '100', 4), 2);
    }
}
