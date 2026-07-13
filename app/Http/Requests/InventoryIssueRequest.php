<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'requisition_id' => ['nullable', 'integer', 'exists:requisitions,id'],
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'stock_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'work_section' => ['nullable', 'string', 'max:255'],
        ];
    }
}
