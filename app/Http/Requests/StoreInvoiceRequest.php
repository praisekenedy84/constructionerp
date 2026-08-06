<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('tax_mode')) {
            $this->merge(['tax_mode' => 'inclusive']);
        }
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where(
                    fn ($query) => $query->where('customer_id', $this->integer('customer_id')),
                ),
            ],
            'phase_id' => [
                'required',
                'integer',
                Rule::exists('project_phases', 'id')->where(
                    fn ($query) => $query->where('project_id', $this->integer('project_id')),
                ),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tax_mode' => ['required', Rule::in(['exclusive', 'inclusive'])],
            'tax_type' => ['nullable', 'string', 'max:100', 'required_with:tax_rate'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deduction_type' => ['nullable', 'string', 'max:100', 'required_with:deduction_rate'],
            'deduction_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'issued'])],
        ];
    }
}
