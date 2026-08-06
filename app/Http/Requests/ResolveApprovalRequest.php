<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResolveApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approved,rejected,amended'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'amendment_reason' => ['nullable', 'string', 'max:2000'],
            'amended_amount' => ['nullable', 'numeric', 'gt:0'],
            'override' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:1000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.days' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'gte:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('action') !== 'amended') {
                return;
            }

            if (! filled($this->input('amendment_reason'))) {
                $validator->errors()->add('amendment_reason', 'An amendment reason is required.');
            }

            if (! is_array($this->input('items')) || count($this->input('items')) < 1) {
                $validator->errors()->add('items', 'At least one amended line item is required.');
            }
        });
    }
}
