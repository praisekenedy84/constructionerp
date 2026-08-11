<?php

namespace App\Http\Requests;

use App\Enums\MoneyAccountType;
use App\Models\MoneyAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePhaseRetentionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        if (! $this->routeIs('projects.retention.release')) {
            return [];
        }

        return [
            'money_account_id' => [
                'required',
                'integer',
                Rule::exists('money_accounts', 'id')->where(function ($query) {
                    $query->where('type', MoneyAccountType::Manager->value)
                        ->where('is_active', true);
                }),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->routeIs('projects.retention.release')) {
            return;
        }

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $account = MoneyAccount::find($this->input('money_account_id'));
            if (! $account || ! $account->isManagerAccount() || ! $account->is_active) {
                $validator->errors()->add(
                    'money_account_id',
                    'Select an active company account for the released retention deposit.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'money_account_id.required' => 'Select a company account to deposit the released retention.',
            'money_account_id.exists' => 'Select an active company account for the released retention deposit.',
        ];
    }
}
