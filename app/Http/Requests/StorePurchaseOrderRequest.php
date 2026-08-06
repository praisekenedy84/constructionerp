<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'requisition_id' => ['required', 'integer', 'exists:requisitions,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'purchase_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'payment_amount' => ['nullable', 'numeric', 'gte:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile', 'bank'])],
            'payment_reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((float) $this->input('payment_amount', 0) <= 0) {
                return;
            }

            if (! in_array($this->input('payment_method'), ['cash', 'mobile', 'bank'], true)) {
                $validator->errors()->add('payment_method', 'Choose a payment method.');
            }

            if (! filled($this->input('payment_reference_no'))) {
                $validator->errors()->add('payment_reference_no', 'Enter the payment reference number.');
            }
        });
    }
}
