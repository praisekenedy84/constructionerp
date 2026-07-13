<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualBudgetAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:1000'],
            'boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
        ];
    }
}
