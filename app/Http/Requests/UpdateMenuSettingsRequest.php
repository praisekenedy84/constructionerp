<?php

namespace App\Http\Requests;

use App\Support\MenuCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMenuSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManagePlatform();
    }

    public function rules(): array
    {
        $hrefs = MenuCatalog::hrefs();
        $keys = MenuCatalog::keys();
        $childKeys = collect(MenuCatalog::childKeysByParent())->flatten()->unique()->values()->all();

        return [
            'role_hidden' => ['nullable', 'array'],
            'role_hidden.*' => ['array'],
            'role_hidden.*.*' => ['string', Rule::in($hrefs)],
            'hidden' => ['nullable', 'array'],
            'hidden.*' => ['string', Rule::in($hrefs)],
            'order' => ['nullable', 'array'],
            'order.*' => ['string', Rule::in($keys)],
            'child_order' => ['nullable', 'array'],
            'child_order.*' => ['array'],
            'child_order.*.*' => ['string', Rule::in($childKeys)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $childOrder = $this->input('child_order', []);
            if (! is_array($childOrder)) {
                return;
            }

            $allowedParents = MenuCatalog::childKeysByParent();

            foreach ($childOrder as $parentKey => $keys) {
                if (! is_string($parentKey) || ! isset($allowedParents[$parentKey])) {
                    $validator->errors()->add(
                        'child_order',
                        'Child order includes an unknown parent menu key.',
                    );
                    continue;
                }

                if (! is_array($keys)) {
                    continue;
                }

                $allowed = $allowedParents[$parentKey];
                foreach ($keys as $childKey) {
                    if (! in_array($childKey, $allowed, true)) {
                        $validator->errors()->add(
                            "child_order.{$parentKey}",
                            "Invalid submenu key “{$childKey}” for “{$parentKey}”.",
                        );
                    }
                }
            }
        });
    }
}
