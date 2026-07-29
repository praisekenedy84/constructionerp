<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryReceiveRequest extends FormRequest
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
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('unit_cost') === '' || $this->input('unit_cost') === null) {
            $this->merge(['unit_cost' => '0']);
        }

        if ($this->input('note') === '') {
            $this->merge(['note' => null]);
        }
    }
}
