<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt_number' => ['required', 'string', 'max:100'],
            'payment_date' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:50'],
            'receipt_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
