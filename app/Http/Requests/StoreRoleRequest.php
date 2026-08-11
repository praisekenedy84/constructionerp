<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:125',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (MenuCatalog::isLockedRole((string) $value)) {
                        $fail('This role name is reserved.');
                    }
                },
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('guard_name', 'web')),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(ModulePermission::allPermissionNames())],
            'copy_from' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('roles', 'name')->where(fn ($q) => $q->where('guard_name', 'web')),
            ],
        ];
    }
}
