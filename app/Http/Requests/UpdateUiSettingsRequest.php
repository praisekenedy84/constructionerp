<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        return [
            'app_name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_contact' => ['nullable', 'string', 'max:255'],
            'company_logo_url' => ['nullable', 'string', 'max:1000'],
            'nav_overrides' => ['nullable', 'array'],
        ];
    }
}
