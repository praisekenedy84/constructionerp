<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'project_id' => ['nullable', 'integer', 'exists:projects,id', 'required_if:category,direct'],
            'boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'sub_type' => ['required', 'string', 'max:100'],
            'activity_ref' => ['nullable', 'string', 'max:100'],
            'asset_reg_no' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'expense_date' => ['required', 'date'],
            'cash_allocation_id' => [
                'nullable',
                'integer',
                'exists:cash_allocations,id',
                'required',
            ],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'payee' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cash_allocation_id' => 'cash float',
            'method' => 'payment method',
            'reference_no' => 'receipt number',
        ];
    }
}
