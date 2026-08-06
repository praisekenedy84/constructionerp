<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecipientAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'check_in' => $this->filled('check_in') ? $this->input('check_in') : null,
            'check_out' => $this->filled('check_out') ? $this->input('check_out') : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'status' => $this->input('status') ?: 'present',
        ]);
    }

    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', 'exists:recipients,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'date' => ['required', 'date'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'status' => ['required', Rule::in(['present', 'absent'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
