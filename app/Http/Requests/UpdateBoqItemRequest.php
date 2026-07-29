<?php

namespace App\Http\Requests;

use App\Enums\BoqItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoqItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'section' => trim((string) $this->input('section', '')),
            'description' => trim((string) $this->input('description', '')),
            'unit' => trim((string) $this->input('unit', '')),
            'category' => trim((string) $this->input('category', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'section' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:500'],
            'unit' => ['required', 'string', 'max:50'],
            'category' => ['required', Rule::enum(BoqItemCategory::class)],
            'budgeted_qty' => ['required', 'numeric', 'gt:0'],
            'unit_rate' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
