<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signature_type' => ['required', Rule::in(['prepared_by', 'approved_by'])],
            'signature_file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'signed_date' => ['nullable', 'date'],
        ];
    }
}
