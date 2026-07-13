<?php

namespace App\Http\Requests;

use App\Enums\ReportScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'report_slug' => ['required', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'frequency' => ['required', Rule::enum(ReportScheduleFrequency::class)],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
            'parameters' => ['nullable', 'array'],
        ];
    }
}
