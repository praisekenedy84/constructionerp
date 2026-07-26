<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'stock_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'new_quantity' => ['required', 'numeric', 'gte:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
