<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionResourceType;
use App\Models\Department;
use App\Models\Position;
use App\Models\Recipient;
use App\Models\Requisition;
use App\Models\RequisitionCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $addressedTo = $this->input('addressed_to') ?: RequisitionAddressedTo::Finance->value;
        $fulfillmentType = $this->input('fulfillment_type');
        $departmentId = $this->input('department_id') ?: null;

        // Legacy posts that only sent a free-text department: map / create a department row.
        if (! $departmentId && $this->filled('department')) {
            $name = trim((string) $this->input('department'));
            if ($name !== '') {
                $departmentId = Department::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->value('id');

                if (! $departmentId) {
                    $departmentId = Department::query()->create([
                        'name' => $name,
                        'is_active' => true,
                        'sort_order' => 0,
                    ])->id;
                }
            }
        }

        $departmentName = null;
        if ($departmentId) {
            $departmentName = Department::query()->whereKey($departmentId)->value('name');
        }

        $expectedExpenseType = $this->filled('project_id')
            ? ExpenseCategory::Direct
            : ExpenseCategory::Indirect;

        $fallbackCategoryId = RequisitionCategory::query()
            ->active()
            ->forExpenseType($expectedExpenseType)
            ->ordered()
            ->value('id');

        // Legacy header-only category / recipient → applied to lines that omit them.
        $legacyCategoryIds = collect($this->input('requisition_category_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($legacyCategoryIds === [] && $this->filled('requisition_category_id')) {
            $legacyCategoryIds = [(int) $this->input('requisition_category_id')];
        }
        if ($legacyCategoryIds === [] && $this->filled('resource_type')) {
            $label = RequisitionResourceType::tryFrom((string) $this->input('resource_type'))?->label();
            if ($label) {
                $mapped = RequisitionCategory::query()
                    ->where('name', $label)
                    ->forExpenseType($expectedExpenseType)
                    ->active()
                    ->ordered()
                    ->value('id');
                if ($mapped) {
                    $legacyCategoryIds = [(int) $mapped];
                }
            }
        }
        $legacyCategoryId = $legacyCategoryIds[0] ?? ($fallbackCategoryId ? (int) $fallbackCategoryId : null);

        $legacyRecipientId = $this->input('recipient_id') ?: null;
        $legacyRecipient = $legacyRecipientId
            ? Recipient::query()->find((int) $legacyRecipientId)
            : null;
        $legacyRecipientName = $legacyRecipient?->name
            ?? ($this->filled('recipient_name') ? trim((string) $this->input('recipient_name')) : null);
        $legacyPositionId = $this->input('position_id') ?: null;
        if (! $legacyPositionId && $this->filled('recipient_position')) {
            $legacyPositionId = $this->resolvePositionId(trim((string) $this->input('recipient_position')));
        }
        $legacyPositionName = $legacyPositionId
            ? Position::query()->whereKey($legacyPositionId)->value('name')
            : null;

        $items = collect($this->input('items', []))->map(function (array $item) use (
            $legacyCategoryId,
            $legacyRecipientId,
            $legacyRecipientName,
            $legacyPositionId,
            $legacyPositionName,
        ) {
            $categoryId = ! empty($item['requisition_category_id'])
                ? (int) $item['requisition_category_id']
                : $legacyCategoryId;

            $positionId = array_key_exists('position_id', $item)
                ? ($item['position_id'] ?: null)
                : $legacyPositionId;
            $positionId = $positionId ? (int) $positionId : null;
            $positionName = $positionId
                ? Position::query()->whereKey($positionId)->value('name')
                : null;

            $recipientId = array_key_exists('recipient_id', $item)
                ? ($item['recipient_id'] ?: null)
                : $legacyRecipientId;
            $recipientId = $recipientId ? (int) $recipientId : null;
            $recipient = $recipientId ? Recipient::query()->find($recipientId) : null;

            $recipientName = $recipient?->name;
            if ($recipientName === null && array_key_exists('recipient_name', $item)) {
                $recipientName = trim((string) ($item['recipient_name'] ?? ''));
                $recipientName = $recipientName !== '' ? $recipientName : null;
            }
            if ($recipientName === null) {
                $recipientName = $legacyRecipientName;
            }

            $days = array_key_exists('days', $item) && $item['days'] !== '' && $item['days'] !== null
                ? $item['days']
                : null;

            return [
                ...$item,
                'boq_item_id' => ! empty($item['boq_item_id']) ? $item['boq_item_id'] : null,
                'inventory_item_id' => ! empty($item['inventory_item_id']) ? $item['inventory_item_id'] : null,
                'unit' => ! empty($item['unit']) ? $item['unit'] : null,
                'days' => $days,
                'requisition_category_id' => $categoryId,
                'recipient_id' => $recipientId,
                'recipient_name' => $recipientName,
                'position_id' => $positionId,
                'recipient_position' => $positionName ?? $legacyPositionName,
            ];
        })->all();

        $categoryIds = collect($items)
            ->pluck('requisition_category_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($categoryIds === [] && $legacyCategoryId) {
            $categoryIds = [$legacyCategoryId];
        }

        $recipients = collect($items)
            ->filter(fn (array $item) => ($item['recipient_id'] ?? null) || ($item['recipient_name'] ?? null) || ($item['position_id'] ?? null))
            ->map(function (array $item) {
                $recipientId = ! empty($item['recipient_id']) ? (int) $item['recipient_id'] : null;
                $recipient = $recipientId ? Recipient::query()->find($recipientId) : null;

                return [
                    'recipient_id' => $recipientId,
                    'name' => $recipient?->name ?? ($item['recipient_name'] ?? '—'),
                    'phone' => $recipient?->phone,
                    'position_id' => $item['position_id'] ?? null,
                    'position_name' => $item['recipient_position'] ?? null,
                ];
            })
            ->unique(fn (array $row) => ($row['recipient_id'] ?? '').'|'.mb_strtolower(($row['name'] ?? '').'|'.($row['position_id'] ?? '')))
            ->values()
            ->all();

        // Also accept explicit header recipients payload (legacy / edit shims).
        if ($recipients === [] && is_array($this->input('recipients'))) {
            $recipients = $this->normalizeRecipients($this->input('recipients', []));
        }

        $primaryRecipient = $recipients[0] ?? null;

        if ($addressedTo === RequisitionAddressedTo::Storekeeper->value) {
            $fulfillmentType = FulfillmentType::StockIssue->value;
        } elseif ($addressedTo === RequisitionAddressedTo::Finance->value
            && $fulfillmentType === FulfillmentType::StockIssue->value) {
            $fulfillmentType = FulfillmentType::CashDisbursement->value;
        }

        if (! $fulfillmentType) {
            $fulfillmentType = $addressedTo === RequisitionAddressedTo::Storekeeper->value
                ? FulfillmentType::StockIssue->value
                : FulfillmentType::CashDisbursement->value;
        }

        $this->merge([
            'project_id' => $this->input('project_id') ?: null,
            'boq_item_id' => $this->input('project_id')
                ? ($this->input('boq_item_id') ?: null)
                : null,
            'department_id' => $departmentId,
            'department' => $departmentName ?? trim((string) $this->input('department', '')),
            'requisition_category_ids' => $categoryIds,
            'requisition_category_id' => $categoryIds[0] ?? null,
            'resource_type' => RequisitionResourceType::Other->value,
            'addressed_to' => $addressedTo,
            'fulfillment_type' => $fulfillmentType,
            'recipients' => $recipients,
            'recipient_id' => $primaryRecipient['recipient_id'] ?? null,
            'recipient_name' => $primaryRecipient['name'] ?? null,
            'position_id' => $primaryRecipient['position_id'] ?? null,
            'recipient_position' => $primaryRecipient['position_name'] ?? null,
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'department' => ['required', 'string', 'max:255'],
            'requisition_category_ids' => ['required', 'array', 'min:1'],
            'requisition_category_ids.*' => ['integer', 'exists:requisition_categories,id'],
            'requisition_category_id' => ['nullable', 'integer', 'exists:requisition_categories,id'],
            'resource_type' => ['required', Rule::enum(RequisitionResourceType::class)],
            'addressed_to' => ['required', Rule::enum(RequisitionAddressedTo::class)],
            'fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.recipient_id' => ['nullable', 'integer', 'exists:recipients,id'],
            'recipients.*.name' => ['required', 'string', 'max:255'],
            'recipients.*.phone' => ['nullable', 'string', 'max:50'],
            'recipients.*.position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'recipients.*.position_name' => ['nullable', 'string', 'max:255'],
            'recipient_id' => ['nullable', 'integer', 'exists:recipients,id'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'recipient_position' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'items.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.requisition_category_id' => ['required', 'integer', 'exists:requisition_categories,id'],
            'items.*.recipient_id' => ['required', 'integer', 'exists:recipients,id'],
            'items.*.recipient_name' => ['nullable', 'string', 'max:255'],
            'items.*.position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'items.*.recipient_position' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.days' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'items.*.details' => ['nullable', 'array'],
            'items.*.details.employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'items.*.details.payroll_run_id' => ['nullable', 'integer'],
            'items.*.details.payroll_item_id' => ['nullable', 'integer'],
            'items.*.details.days' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->input('project_id') && $this->filled('boq_item_id')) {
                $validator->errors()->add(
                    'boq_item_id',
                    'Administrative requests cannot be linked to a project BOQ line.'
                );
            }

            $departmentId = (int) $this->input('department_id');
            if ($departmentId > 0) {
                $department = Department::query()->find($departmentId);
                if ($department && ! $department->is_active) {
                    $requisitionId = $this->route('id');
                    $existingDepartmentId = $requisitionId
                        ? Requisition::query()->whereKey($requisitionId)->value('department_id')
                        : null;

                    if ((int) $existingDepartmentId !== $departmentId) {
                        $validator->errors()->add(
                            'department_id',
                            'Select an active department.'
                        );
                    }
                }
            }

            $requisitionId = $this->route('id');
            $existing = $requisitionId
                ? Requisition::query()->with(['categories', 'items'])->find($requisitionId)
                : null;
            $existingCategoryIds = $existing
                ? $existing->categories->pluck('id')
                    ->merge($existing->items->pluck('requisition_category_id'))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->all()
                : [];
            $existingPositionIds = $existing
                ? $existing->items->pluck('position_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->all()
                : [];
            $existingRecipientIds = $existing
                ? $existing->items->pluck('recipient_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->all()
                : [];

            $expectedExpenseType = $this->filled('project_id')
                ? ExpenseCategory::Direct
                : ExpenseCategory::Indirect;

            foreach ($this->input('items', []) as $index => $item) {
                $categoryId = (int) ($item['requisition_category_id'] ?? 0);
                if ($categoryId > 0) {
                    $category = RequisitionCategory::query()->find($categoryId);
                    if ($category && ! $category->is_active && ! in_array($categoryId, $existingCategoryIds, true)) {
                        $validator->errors()->add(
                            "items.{$index}.requisition_category_id",
                            'Select an active requisition category.'
                        );
                    }
                    if (
                        $category
                        && $category->expense_type !== $expectedExpenseType
                        && ! in_array($categoryId, $existingCategoryIds, true)
                    ) {
                        $validator->errors()->add(
                            "items.{$index}.requisition_category_id",
                            $expectedExpenseType === ExpenseCategory::Direct
                                ? 'Select a project (direct expense) category for this requisition.'
                                : 'Select an administrative (indirect expense) category for this requisition.'
                        );
                    }
                }

                $positionId = (int) ($item['position_id'] ?? 0);
                if ($positionId > 0) {
                    $position = Position::query()->find($positionId);
                    if ($position && ! $position->is_active && ! in_array($positionId, $existingPositionIds, true)) {
                        $validator->errors()->add(
                            "items.{$index}.position_id",
                            'Select an active position.'
                        );
                    }
                }

                $recipientId = (int) ($item['recipient_id'] ?? 0);
                if ($recipientId > 0) {
                    $recipient = Recipient::query()->find($recipientId);
                    if ($recipient && ! $recipient->isActive() && ! in_array($recipientId, $existingRecipientIds, true)) {
                        $validator->errors()->add(
                            "items.{$index}.recipient_id",
                            'Select an active recipient. Register inactive recipients again before use.'
                        );
                    }
                }

                if (! empty($item['inventory_item_id']) || ! empty($item['description'])) {
                    continue;
                }

                $validator->errors()->add(
                    "items.{$index}.description",
                    'Provide a description, or pick an item from the catalog.'
                );
            }

            $addressedTo = (string) $this->input('addressed_to');
            $fulfillmentType = (string) $this->input('fulfillment_type');

            if ($addressedTo === RequisitionAddressedTo::Storekeeper->value
                && $fulfillmentType !== FulfillmentType::StockIssue->value) {
                $validator->errors()->add(
                    'fulfillment_type',
                    'Storekeeper requests must be fulfilled as a stock issue.'
                );
            }

            if ($addressedTo === RequisitionAddressedTo::Finance->value
                && $fulfillmentType === FulfillmentType::StockIssue->value) {
                $validator->errors()->add(
                    'fulfillment_type',
                    'Finance requests cannot use stock issue. Choose cash or supplier payment.'
                );
            }
        });
    }

    /**
     * @param  mixed  $raw
     * @return list<array{recipient_id: int|null, name: string, phone: string|null, position_id: int|null, position_name: string|null}>
     */
    private function normalizeRecipients(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }

            $recipientId = ! empty($recipient['recipient_id']) ? (int) $recipient['recipient_id'] : null;
            $master = $recipientId ? Recipient::query()->find($recipientId) : null;
            $name = $master?->name ?? trim((string) ($recipient['name'] ?? ''));
            $positionId = ! empty($recipient['position_id']) ? (int) $recipient['position_id'] : null;

            if ($name === '' && ! $positionId && ! $recipientId) {
                continue;
            }

            $positionName = null;
            if ($positionId) {
                $positionName = Position::query()->whereKey($positionId)->value('name');
            }

            $normalized[] = [
                'recipient_id' => $recipientId,
                'name' => $name !== '' ? $name : '—',
                'phone' => $master?->phone,
                'position_id' => $positionId,
                'position_name' => $positionName,
            ];
        }

        return $normalized;
    }

    private function resolvePositionId(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $positionId = Position::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        if ($positionId) {
            return (int) $positionId;
        }

        return Position::query()->create([
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ])->id;
    }
}
