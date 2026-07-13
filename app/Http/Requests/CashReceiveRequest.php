<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'method' => ['nullable', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
