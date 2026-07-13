<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'boq_item_id' => ['required', 'integer', 'exists:boq_items,id'],
            'department' => ['required', 'string', 'max:255'],
            'fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['required', 'integer', 'exists:boq_items,id'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
