<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                // Prefer extension checks — Windows often reports xlsx as octet-stream.
                'extensions:csv,txt,xlsx,xls',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please choose a BOQ file to upload.',
            'file.extensions' => 'The file must be a CSV or Excel file (.csv, .xlsx, .xls).',
        ];
    }
}
