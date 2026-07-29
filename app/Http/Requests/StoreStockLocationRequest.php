<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stock_locations', 'name')
                    ->where(fn ($query) => $query
                        ->where('project_id', $this->input('project_id'))
                        ->whereNull('deleted_at')),
            ],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'That location name already exists for this project.',
        ];
    }
}
