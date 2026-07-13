<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EquipmentFuelRequest extends FormRequest
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
            'assignment_id' => ['nullable', 'integer', 'exists:equipment_assignments,id'],
            'liters' => ['required', 'numeric', 'gt:0'],
            'cost' => ['required', 'numeric', 'gte:0'],
            'date' => ['required', 'date'],
        ];
    }
}
