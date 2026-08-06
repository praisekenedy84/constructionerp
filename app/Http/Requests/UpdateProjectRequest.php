<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'wht_percentage' => $this->blankToNull($this->input('wht_percentage')) ?? 0,
            'location' => $this->input('location') ?: '',
            'client' => $this->input('client') ?: '',
            'client_phone' => $this->input('client_phone') ?: '',
            'client_email' => $this->input('client_email') ?: null,
            'client_tin' => $this->input('client_tin') ?: '',
        ]);
    }

    public function rules(): array
    {
        $projectId = (int) $this->route('id');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('projects', 'code')->ignore($projectId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'client' => ['required', 'string', 'max:255'],
            'client_phone' => ['required', 'string', 'max:50'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_tin' => ['required', 'string', 'max:100'],
            'location' => ['required', 'string', 'max:255'],
            'contract_amount' => ['required', 'numeric', 'min:0'],
            'wht_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'recipient_ids' => ['nullable', 'array'],
            'recipient_ids.*' => ['integer', 'exists:recipients,id'],
        ];
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
