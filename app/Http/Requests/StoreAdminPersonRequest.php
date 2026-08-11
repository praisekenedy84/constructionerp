<?php

namespace App\Http\Requests;

use App\Enums\PayStructure;
use App\Support\MenuCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreAdminPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'create_user' => filter_var($this->input('create_user'), FILTER_VALIDATE_BOOLEAN),
            'create_staff' => filter_var($this->input('create_staff'), FILTER_VALIDATE_BOOLEAN),
            'user_id' => $this->filled('user_id') ? $this->input('user_id') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'create_user' => ['required', 'boolean'],
            'create_staff' => ['required', 'boolean'],
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'nullable',
                'required_if:create_user,true',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'nullable',
                'required_if:create_user,true',
                'confirmed',
                Password::defaults(),
            ],
            'access_role' => [
                'nullable',
                'required_if:create_user,true',
                'string',
                Rule::in(MenuCatalog::assignableRoles()),
            ],

            'employee_no' => [
                'nullable',
                'required_if:create_staff,true',
                'string',
                'max:50',
                'unique:employees,employee_no',
            ],
            'job_role' => [
                'nullable',
                'required_if:create_staff,true',
                'string',
                'max:100',
            ],
            'pay_structure' => [
                'nullable',
                'required_if:create_staff,true',
                Rule::enum(PayStructure::class),
            ],
            'daily_rate' => ['nullable', 'numeric', 'gte:0', 'required_if:pay_structure,daily'],
            'monthly_salary' => ['nullable', 'numeric', 'gte:0', 'required_if:pay_structure,monthly'],
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            'user_id' => [
                'nullable',
                'prohibited_if:create_user,true',
                'integer',
                'exists:users,id',
                'unique:employees,user_id',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('create_user') && ! $this->boolean('create_staff')) {
                $validator->errors()->add(
                    'create_user',
                    'Select at least a login account or a staff record.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'access_role.required_if' => 'An access role is required when creating a login account.',
            'job_role.required_if' => 'A job role is required when creating a staff record.',
            'user_id.prohibited_if' => 'Cannot link an existing user when creating a new login account.',
        ];
    }
}
