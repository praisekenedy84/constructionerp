<?php

namespace App\Http\Requests;

use App\Enums\ComplianceRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplianceRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
            'rule_type' => (string) $this->input('rule_type', ComplianceRuleType::Other->value),
            'is_active' => filter_var($this->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function rules(): array
    {
        $ruleId = (int) $this->route('id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('compliance_rules', 'name')->ignore($ruleId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'rule_type' => ['required', Rule::enum(ComplianceRuleType::class)],
            'is_active' => ['boolean'],
        ];
    }
}
