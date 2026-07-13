<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EquipmentAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'equipment_id' => ['required', 'integer', 'exists:equipment,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'hours_budgeted' => ['nullable', 'numeric', 'gte:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
