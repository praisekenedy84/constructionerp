<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'received_amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
