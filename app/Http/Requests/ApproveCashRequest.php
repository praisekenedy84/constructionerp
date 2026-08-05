<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'source_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'approved_amount' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('approved_amount') === '') {
            $this->merge(['approved_amount' => null]);
        }
    }
}
