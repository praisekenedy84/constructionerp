<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncProjectRecipientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'recipient_ids' => ['nullable', 'array'],
            'recipient_ids.*' => ['integer', 'exists:recipients,id'],
        ];
    }
}
