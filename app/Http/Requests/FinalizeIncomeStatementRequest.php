<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizeIncomeStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nullable = [
            'from',
            'to',
            'project_id',
            'memo_no',
            'interest',
            'depreciation',
            'corporate_tax',
        ];

        foreach ($nullable as $field) {
            if ($this->input($field) === '' || $this->input($field) === null) {
                $this->merge([$field => null]);
            }
        }

        $adhoc = $this->input('adhoc');
        if (is_array($adhoc)) {
            $this->merge([
                'adhoc' => array_map(function ($row) {
                    if (! is_array($row)) {
                        return $row;
                    }
                    if (($row['value'] ?? null) === '' || ($row['value'] ?? null) === null) {
                        $row['value'] = $row['amount'] ?? null;
                    }
                    if (($row['value'] ?? null) === '') {
                        $row['value'] = null;
                    }

                    return $row;
                }, $adhoc),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'memo_no' => ['nullable', 'string', 'max:100'],
            'interest' => ['nullable', 'numeric', 'min:0'],
            'interest_mode' => ['nullable', Rule::in(['fixed', 'percent'])],
            'depreciation' => ['nullable', 'numeric', 'min:0'],
            'depreciation_mode' => ['nullable', Rule::in(['fixed', 'percent'])],
            'corporate_tax' => ['nullable', 'numeric', 'min:0'],
            'corporate_tax_mode' => ['nullable', Rule::in(['fixed', 'percent'])],
            'adhoc' => ['nullable', 'array', 'max:50'],
            'adhoc.*.label' => ['required_with:adhoc', 'string', 'max:120'],
            'adhoc.*.value' => ['nullable', 'numeric'],
            'adhoc.*.amount' => ['nullable', 'numeric'],
            'adhoc.*.mode' => ['nullable', Rule::in(['fixed', 'percent'])],
            'adhoc.*.section' => ['required_with:adhoc', Rule::in(['revenue', 'direct', 'indirect', 'below_ebitda'])],
            'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    /**
     * @return array{
     *     from?: string|null,
     *     to?: string|null,
     *     project_id?: int|null,
     *     memo_no?: string|null,
     * }
     */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'memo_no' => $data['memo_no'] ?? null,
        ];
    }

    /**
     * @return array{
     *     interest: string,
     *     interest_mode: string,
     *     depreciation: string,
     *     depreciation_mode: string,
     *     corporate_tax: string,
     *     corporate_tax_mode: string,
     *     adhoc: list<array{label: string, value: string, mode: string, section: string}>
     * }
     */
    public function adjustments(): array
    {
        $data = $this->validated();

        return [
            'interest' => (string) ($data['interest'] ?? '0'),
            'interest_mode' => (string) ($data['interest_mode'] ?? 'fixed'),
            'depreciation' => (string) ($data['depreciation'] ?? '0'),
            'depreciation_mode' => (string) ($data['depreciation_mode'] ?? 'fixed'),
            'corporate_tax' => (string) ($data['corporate_tax'] ?? '0'),
            'corporate_tax_mode' => (string) ($data['corporate_tax_mode'] ?? 'fixed'),
            'adhoc' => array_values(array_map(
                function (array $row) {
                    $value = $row['value'] ?? $row['amount'] ?? '0';

                    return [
                        'label' => (string) $row['label'],
                        'value' => (string) $value,
                        'mode' => (string) ($row['mode'] ?? 'fixed'),
                        'section' => (string) $row['section'],
                    ];
                },
                $data['adhoc'] ?? [],
            )),
        ];
    }
}
