<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

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
        return urldecode((string) $this->route('role'));
    }

    protected function prepareForValidation(): void
    {
        $roleName = $this->roleName();

        if (MenuCatalog::isLockedRole($roleName)) {
            abort(403, 'This role cannot be edited.');
        }

        $exists = Role::where('name', $roleName)->where('guard_name', 'web')->exists();

        if (! $exists) {
            abort(404, 'Role not found.');
        }
    }
}
