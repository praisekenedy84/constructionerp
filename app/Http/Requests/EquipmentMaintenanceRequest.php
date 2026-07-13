<?php

namespace App\Http\Requests;

use App\Enums\EquipmentMaintenanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentMaintenanceRequest extends FormRequest
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
            'type' => ['required', Rule::enum(EquipmentMaintenanceType::class)],
            'cost' => ['required', 'numeric', 'gte:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'date' => ['required', 'date'],
        ];
    }
}
