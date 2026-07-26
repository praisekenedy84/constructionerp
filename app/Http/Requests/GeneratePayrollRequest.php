<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'overrides' => ['nullable', 'array'],
            'overrides.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'overrides.*.net_pay' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
