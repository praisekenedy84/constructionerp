<?php

namespace App\Http\Requests;

use App\Enums\ComplianceCalculationType;
use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $ipcs = collect($this->input('ipcs', []))
            ->map(function (array $ipc) {
                $items = collect($ipc['compliance_items'] ?? [])
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

                return ['compliance_items' => $items];
            })
            // Drop completely empty IPC shells (no filled compliance rows).
            ->filter(fn (array $ipc) => $ipc['compliance_items'] !== [])
            ->values()
            ->all();

        $this->merge([
            'wht_percentage' => $this->blankToNull($this->input('wht_percentage')) ?? 0,
            'location' => $this->input('location') ?: '',
            'client' => $this->input('client') ?: '',
            'ipcs' => $ipcs,
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
            'ipcs' => ['nullable', 'array'],
            'ipcs.*.compliance_items' => ['required', 'array', 'min:1'],
            'ipcs.*.compliance_items.*.compliance_rule_id' => [
                'required',
                'integer',
                Rule::exists('compliance_rules', 'id')->where(
                    fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'ipcs.*.compliance_items.*.calculation_type' => ['required', Rule::enum(ComplianceCalculationType::class)],
            'ipcs.*.compliance_items.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ipcs.*.compliance_items.*.fixed_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('ipcs', []) as $ipcIndex => $ipc) {
                if (! is_array($ipc)) {
                    continue;
                }

                $seen = [];
                foreach ($ipc['compliance_items'] ?? [] as $itemIndex => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $ruleId = (int) ($item['compliance_rule_id'] ?? 0);
                    if ($ruleId > 0) {
                        if (isset($seen[$ruleId])) {
                            $validator->errors()->add(
                                "ipcs.{$ipcIndex}.compliance_items.{$itemIndex}.compliance_rule_id",
                                'This compliance rule is already added to this IPC.',
                            );
                        }
                        $seen[$ruleId] = true;
                    }

                    $type = $item['calculation_type'] ?? null;

                    if ($type === ComplianceCalculationType::RatePercent->value) {
                        $rate = $item['rate'] ?? null;
                        if ($rate === null || $rate === '' || (float) $rate <= 0) {
                            $validator->errors()->add(
                                "ipcs.{$ipcIndex}.compliance_items.{$itemIndex}.rate",
                                'Enter a rate % greater than zero.',
                            );
                        }
                    }

                    if ($type === ComplianceCalculationType::FixedAmount->value) {
                        $fixed = $item['fixed_amount'] ?? null;
                        if ($fixed === null || $fixed === '' || (float) $fixed <= 0) {
                            $validator->errors()->add(
                                "ipcs.{$ipcIndex}.compliance_items.{$itemIndex}.fixed_amount",
                                'Enter a fixed amount greater than zero.',
                            );
                        }
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
