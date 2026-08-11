<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        $current = $this->roleName();

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
                Rule::unique('roles', 'name')->where(function ($query) use ($current) {
                    $query->where('guard_name', 'web')->where('name', '!=', $current);
                }),
            ],
        ];
    }

    public function roleName(): string
    {
        return urldecode((string) $this->route('role'));
    }

    protected function prepareForValidation(): void
    {
        if (MenuCatalog::isLockedRole($this->roleName())) {
            abort(403, 'This role cannot be renamed.');
        }
    }
}
