<?php

namespace App\Http\Requests;

use App\Enums\ComplianceCalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectComplianceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('compliance_items', []))
            ->filter(fn ($item) => filled($item['compliance_rule_id'] ?? null)
                || filled($item['rate'] ?? null)
                || filled($item['fixed_amount'] ?? null))
            ->map(function (array $item) {
                $type = $item['calculation_type'] ?? ComplianceCalculationType::RatePercent->value;

                return [
                    'compliance_rule_id' => filled($item['compliance_rule_id'] ?? null)
                        ? (int) $item['compliance_rule_id']
                        : null,
                    'calculation_type' => $type,
                    'rate' => $type === ComplianceCalculationType::RatePercent->value
                        ? $this->blankToNull($item['rate'] ?? null)
                        : null,
                    'fixed_amount' => $type === ComplianceCalculationType::FixedAmount->value
                        ? $this->blankToNull($item['fixed_amount'] ?? null)
                        : null,
                ];
            })
            ->values()
            ->all();

        $this->merge(['compliance_items' => $items]);
    }

    public function rules(): array
    {
        return [
            'compliance_items' => ['required', 'array', 'min:1'],
            'compliance_items.*.compliance_rule_id' => [
                'required',
                'integer',
                Rule::exists('compliance_rules', 'id')->where(
                    fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'compliance_items.*.calculation_type' => ['required', Rule::enum(ComplianceCalculationType::class)],
            'compliance_items.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'compliance_items.*.fixed_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $seen = [];
            foreach ($this->input('compliance_items', []) as $itemIndex => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $ruleId = (int) ($item['compliance_rule_id'] ?? 0);
                if ($ruleId > 0) {
                    if (isset($seen[$ruleId])) {
                        $validator->errors()->add(
                            "compliance_items.{$itemIndex}.compliance_rule_id",
                            'This compliance rule is already added.',
                        );
                    }
                    $seen[$ruleId] = true;
                }

                $type = $item['calculation_type'] ?? null;

                if ($type === ComplianceCalculationType::RatePercent->value) {
                    $rate = $item['rate'] ?? null;
                    if ($rate === null || $rate === '' || (float) $rate <= 0) {
                        $validator->errors()->add(
                            "compliance_items.{$itemIndex}.rate",
                            'Enter a rate % greater than zero.',
                        );
                    }
                }

                if ($type === ComplianceCalculationType::FixedAmount->value) {
                    $fixed = $item['fixed_amount'] ?? null;
                    if ($fixed === null || $fixed === '' || (float) $fixed <= 0) {
                        $validator->errors()->add(
                            "compliance_items.{$itemIndex}.fixed_amount",
                            'Enter a fixed amount greater than zero.',
                        );
                    }
                }
            }
        });
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
