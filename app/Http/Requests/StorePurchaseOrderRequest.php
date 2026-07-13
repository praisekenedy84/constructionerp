<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'requisition_id' => ['required', 'integer', 'exists:requisitions,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'boq_item_id' => ['required', 'integer', 'exists:boq_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
