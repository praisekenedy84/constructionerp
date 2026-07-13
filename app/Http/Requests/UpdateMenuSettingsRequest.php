<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        $roles = MenuCatalog::tenantRoles();
        $hrefs = MenuCatalog::hrefs();

        return [
            'role_hidden' => ['nullable', 'array'],
            'role_hidden.*' => ['array'],
            'role_hidden.*.*' => ['string', Rule::in($hrefs)],
            'hidden' => ['nullable', 'array'],
            'hidden.*' => ['string', Rule::in($hrefs)],
        ];
    }
}
