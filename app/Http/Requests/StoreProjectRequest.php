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
            'compliance_rules.*.rule_type' => ['required_with:compliance_rules', Rule::enum(ComplianceRuleType::class)],
            'compliance_rules.*.rate' => ['required_with:compliance_rules', 'numeric', 'min:0'],
            'compliance_rules.*.is_active' => ['nullable', 'boolean'],
            'compliance_rules.*.max_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
