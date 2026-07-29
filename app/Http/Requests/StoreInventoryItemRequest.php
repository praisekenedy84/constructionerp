<?php

namespace App\Http\Requests;

use App\Enums\InventoryItemCategory;
use App\Models\InventoryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', 'unique:inventory_items,code'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'category' => ['required', Rule::enum(InventoryItemCategory::class)],
            'reorder_point' => ['nullable', 'numeric', 'gte:0'],
            'opening_quantity' => ['nullable', 'numeric', 'gt:0'],
            'stock_location_id' => ['nullable', 'integer', 'exists:stock_locations,id'],
            'unit_cost' => ['nullable', 'numeric', 'gte:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasQty = filled($this->input('opening_quantity'));
            $hasLocation = filled($this->input('stock_location_id'));

            if ($hasQty xor $hasLocation) {
                $validator->errors()->add(
                    $hasQty ? 'stock_location_id' : 'opening_quantity',
                    'Opening stock needs both a quantity and a location.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['reorder_point', 'opening_quantity', 'stock_location_id', 'unit_cost'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if ($this->input('unit_cost') === null && filled($this->input('opening_quantity'))) {
            $this->merge(['unit_cost' => '0']);
        }

        $code = trim((string) $this->input('code', ''));
        if ($code === '' && filled($this->input('name'))) {
            $this->merge([
                'code' => InventoryItem::generateUniqueCode((string) $this->input('name')),
            ]);
        } elseif ($code !== '') {
            $this->merge(['code' => strtoupper($code)]);
        }
    }
}
