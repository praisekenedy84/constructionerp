<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // null / empty = organization-wide (general) fund request
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'requested_amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('project_id') === '' || $this->input('project_id') === 'organization') {
            $this->merge(['project_id' => null]);
        }
    }
}
