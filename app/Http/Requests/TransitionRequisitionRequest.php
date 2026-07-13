<?php

namespace App\Http\Requests;

use App\Enums\RequisitionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'to_status' => ['required', Rule::enum(RequisitionStatus::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
            'amendment_reason' => ['nullable', 'string', 'max:2000'],
            'amended_amount' => ['nullable', 'numeric', 'gt:0'],
            'override' => ['nullable', 'boolean'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'stock_location_id' => ['nullable', 'integer', 'exists:stock_locations,id'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'work_section' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:100'],
            'payee' => ['nullable', 'string', 'max:255'],
            'cash_allocation_id' => ['nullable', 'integer', 'exists:cash_allocations,id'],
        ];
    }
}
