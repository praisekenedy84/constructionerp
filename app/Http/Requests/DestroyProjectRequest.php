<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestroyProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'confirmation_code' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $project = Project::withTrashed()->find($this->route('id'));
            if (! $project) {
                return;
            }

            if ((string) $this->input('confirmation_code') !== (string) $project->code) {
                $validator->errors()->add(
                    'confirmation_code',
                    'Type the project code exactly to confirm permanent deletion.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'confirmation_code.required' => 'Type the project code to confirm permanent deletion.',
        ];
    }
}
