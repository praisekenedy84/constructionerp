<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use Illuminate\Foundation\Http\FormRequest;

class DestroyRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        return [];
    }

    public function roleName(): string
    {
        return urldecode((string) $this->route('role'));
    }

    protected function prepareForValidation(): void
    {
        if (MenuCatalog::isLockedRole($this->roleName())) {
            abort(403, 'This role cannot be deleted.');
        }
    }
}
