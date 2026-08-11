<?php

namespace App\Http\Requests;

use App\Enums\DepositSource;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepositMoneyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'deposit_source' => [
                'required',
                Rule::enum(DepositSource::class)->except([DepositSource::RetentionRelease]),
            ],
            'creditor_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(function () {
                    $source = DepositSource::tryFrom((string) $this->input('deposit_source'));

                    return $source?->createsDebt() === true;
                }),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
