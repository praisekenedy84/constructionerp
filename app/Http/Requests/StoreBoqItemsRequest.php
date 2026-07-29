<?php

namespace App\Http\Requests;

use App\Enums\BoqItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoqItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->map(function (array $item) {
            return [
                ...$item,
                'section' => trim((string) ($item['section'] ?? '')),
                'description' => trim((string) ($item['description'] ?? '')),
                'unit' => trim((string) ($item['unit'] ?? '')),
                'category' => trim((string) ($item['category'] ?? '')),
            ];
        })->all();

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.section' => ['required', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.category' => ['required', Rule::enum(BoqItemCategory::class)],
            'items.*.budgeted_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_rate' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
