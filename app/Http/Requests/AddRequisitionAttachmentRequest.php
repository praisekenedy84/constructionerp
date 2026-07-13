<?php

namespace App\Http\Requests;

use App\Enums\RequisitionAttachmentDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddRequisitionAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'document_type' => ['required', Rule::enum(RequisitionAttachmentDocumentType::class)],
        ];
    }
}
