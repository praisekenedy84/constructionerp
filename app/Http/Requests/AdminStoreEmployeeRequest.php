<?php

namespace App\Http\Requests;

use App\Enums\PayStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminStoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        return [
            'employee_no' => ['required', 'string', 'max:50', 'unique:employees,employee_no'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:100'],
            'pay_structure' => ['required', Rule::enum(PayStructure::class)],
            'daily_rate' => ['nullable', 'numeric', 'gte:0', 'required_if:pay_structure,daily'],
            'monthly_salary' => ['nullable', 'numeric', 'gte:0', 'required_if:pay_structure,monthly'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:employees,user_id'],
        ];
    }
}
