<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(ModulePermission::allPermissionNames())],
        ];
    }

    public function roleName(): string
    {
        return (string) $this->route('role');
    }

    protected function prepareForValidation(): void
    {
        if (! in_array($this->roleName(), MenuCatalog::editablePermissionRoles(), true)) {
            abort(403, 'This role cannot be edited.');
        }
    }
}
