<?php

namespace App\Http\Requests;

use App\Enums\ComplianceRuleType;
use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $rules = collect($this->input('compliance_rules', []))
            ->filter(fn ($rule) => filter_var($rule['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->map(function (array $rule) {
                $rate = $this->blankToNull($rule['rate'] ?? null);
                $maxAmount = $this->blankToNull($rule['max_amount'] ?? null);

                return [
                    'rule_type' => $rule['rule_type'] ?? null,
                    // DB rate is required; fixed-only charges store 0%.
                    'rate' => $rate ?? 0,
                    'is_active' => true,
                    'max_amount' => $maxAmount,
                ];
            })
            ->values()
            ->all();

        $this->merge([
            'compliance_rules' => $rules,
            'wht_percentage' => $this->blankToNull($this->input('wht_percentage')) ?? 0,
            'location' => $this->input('location') ?: '',
            'client' => $this->input('client') ?: '',
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:projects,code'],
            'name' => ['required', 'string', 'max:255'],
            'client' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'contract_amount' => ['required', 'numeric', 'min:0'],
            'wht_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'compliance_rules' => ['nullable', 'array'],
            'compliance_rules.*.rule_type' => ['required', Rule::enum(ComplianceRuleType::class)],
            'compliance_rules.*.rate' => ['nullable', 'numeric', 'min:0'],
            'compliance_rules.*.is_active' => ['nullable', 'boolean'],
            'compliance_rules.*.max_amount' => ['nullable', 'numeric', 'min:0'],
            'compliance_rules.*' => [
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_array($value)) {
                        return;
                    }

                    $rate = $value['rate'] ?? null;
                    $maxAmount = $value['max_amount'] ?? null;
                    $hasRate = $rate !== null && $rate !== '' && (float) $rate > 0;
                    $hasFixed = $maxAmount !== null && $maxAmount !== '' && (float) $maxAmount > 0;

                    if (! $hasRate && ! $hasFixed) {
                        $fail('Each active compliance rule needs a rate % or a fixed amount.');
                    }
                },
            ],
        ];
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
