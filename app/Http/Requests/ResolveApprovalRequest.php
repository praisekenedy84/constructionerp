<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
