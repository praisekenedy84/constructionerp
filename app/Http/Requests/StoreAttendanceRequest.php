<?php

namespace App\Http\Requests;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'date' => ['nullable', 'date'],
            'entries.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'entries.*.date' => ['nullable', 'date'],
            'entries.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'entries.*.hours_worked' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
