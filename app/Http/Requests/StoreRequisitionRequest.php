<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionResourceType;
use App\Models\Department;
use App\Models\Position;
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
        $items = collect($this->input('items', []))->map(function (array $item) {
            return [
                ...$item,
                'boq_item_id' => ! empty($item['boq_item_id']) ? $item['boq_item_id'] : null,
                'inventory_item_id' => ! empty($item['inventory_item_id']) ? $item['inventory_item_id'] : null,
                'unit' => ! empty($item['unit']) ? $item['unit'] : null,
            ];
        })->all();

        $addressedTo = $this->input('addressed_to') ?: RequisitionAddressedTo::Finance->value;
        $fulfillmentType = $this->input('fulfillment_type');
        $categoryId = $this->input('requisition_category_id') ?: null;
        $departmentId = $this->input('department_id') ?: null;
        $positionId = $this->input('position_id') ?: null;

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

        // Legacy posts that only sent a free-text position: map / create a position row.
        if (! $positionId && $this->filled('recipient_position')) {
            $name = trim((string) $this->input('recipient_position'));
            if ($name !== '') {
                $positionId = Position::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->value('id');

                if (! $positionId) {
                    $positionId = Position::query()->create([
                        'name' => $name,
                        'is_active' => true,
                        'sort_order' => 0,
                    ])->id;
                }
            }
        }

        $recipientPosition = null;
        if ($positionId) {
            $recipientPosition = Position::query()->whereKey($positionId)->value('name');
        }

        // Legacy posts that only sent resource_type: map to a matching category name.
        if (! $categoryId && $this->filled('resource_type')) {
            $label = RequisitionResourceType::tryFrom((string) $this->input('resource_type'))?->label();
            if ($label) {
                $categoryId = RequisitionCategory::query()
                    ->where('name', $label)
                    ->active()
                    ->ordered()
                    ->value('id');
            }
        }

        if (! $categoryId) {
            $categoryId = RequisitionCategory::query()
                ->active()
                ->ordered()
                ->value('id');
        }

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

        $recipientName = $this->filled('recipient_name')
            ? trim((string) $this->input('recipient_name'))
            : null;

        $this->merge([
            'project_id' => $this->input('project_id') ?: null,
            'boq_item_id' => $this->input('project_id')
                ? ($this->input('boq_item_id') ?: null)
                : null,
            'department_id' => $departmentId,
            'department' => $departmentName ?? trim((string) $this->input('department', '')),
            'requisition_category_id' => $categoryId,
            // Generic line form — resource_type is retained for legacy rows only.
            'resource_type' => RequisitionResourceType::Other->value,
            'addressed_to' => $addressedTo,
            'fulfillment_type' => $fulfillmentType,
            'recipient_name' => $recipientName !== '' ? $recipientName : null,
            'position_id' => $positionId,
            'recipient_position' => $recipientPosition,
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
            'requisition_category_id' => ['required', 'integer', 'exists:requisition_categories,id'],
            'resource_type' => ['required', Rule::enum(RequisitionResourceType::class)],
            'addressed_to' => ['required', Rule::enum(RequisitionAddressedTo::class)],
            'fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'recipient_position' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'items.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->input('project_id') && $this->filled('boq_item_id')) {
                $validator->errors()->add(
                    'boq_item_id',
                    'Organization requests cannot be linked to a project BOQ line.'
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

            $positionId = (int) $this->input('position_id');
            if ($positionId > 0) {
                $position = Position::query()->find($positionId);
                if ($position && ! $position->is_active) {
                    $requisitionId = $this->route('id');
                    $existingPositionId = $requisitionId
                        ? Requisition::query()->whereKey($requisitionId)->value('position_id')
                        : null;

                    if ((int) $existingPositionId !== $positionId) {
                        $validator->errors()->add(
                            'position_id',
                            'Select an active position.'
                        );
                    }
                }
            }

            $categoryId = (int) $this->input('requisition_category_id');
            if ($categoryId > 0) {
                $category = RequisitionCategory::query()->find($categoryId);
                if ($category && ! $category->is_active) {
                    $requisitionId = $this->route('id');
                    $existingCategoryId = $requisitionId
                        ? Requisition::query()->whereKey($requisitionId)->value('requisition_category_id')
                        : null;

                    if ((int) $existingCategoryId !== $categoryId) {
                        $validator->errors()->add(
                            'requisition_category_id',
                            'Select an active requisition category.'
                        );
                    }
                }
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

            foreach ($this->input('items', []) as $index => $item) {
                if (! empty($item['inventory_item_id']) || ! empty($item['description'])) {
                    continue;
                }

                $validator->errors()->add(
                    "items.{$index}.description",
                    'Provide a description, or pick an item from the catalog.'
                );
            }
        });
    }
}
