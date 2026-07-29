<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionResourceType;
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

        $addressedTo = $this->input('addressed_to');
        $fulfillmentType = $this->input('fulfillment_type');

        if ($addressedTo === RequisitionAddressedTo::Storekeeper->value) {
            $fulfillmentType = FulfillmentType::StockIssue->value;
        } elseif ($addressedTo === RequisitionAddressedTo::Finance->value
            && $fulfillmentType === FulfillmentType::StockIssue->value) {
            $resourceType = (string) $this->input('resource_type');
            $fulfillmentType = in_array($resourceType, [
                RequisitionResourceType::Cash->value,
                RequisitionResourceType::Labor->value,
            ], true)
                ? FulfillmentType::CashDisbursement->value
                : FulfillmentType::DirectSupplierPayment->value;
        }

        $this->merge([
            'boq_item_id' => $this->input('boq_item_id') ?: null,
            'addressed_to' => $addressedTo,
            'fulfillment_type' => $fulfillmentType,
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        $resourceType = (string) $this->input('resource_type');

        $rules = [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'department' => ['required', 'string', 'max:255'],
            'resource_type' => ['required', Rule::enum(RequisitionResourceType::class)],
            'addressed_to' => ['required', Rule::enum(RequisitionAddressedTo::class)],
            'fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer', 'exists:boq_items,id'],
            'items.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.description' => ['required', 'string', 'max:500'],
        ];

        return match ($resourceType) {
            RequisitionResourceType::Labor->value => [
                ...$rules,
                'items.*.workers' => ['required', 'numeric', 'gt:0'],
                'items.*.days' => ['required', 'numeric', 'gt:0'],
                'items.*.rate_per_day' => ['required', 'numeric', 'gte:0'],
            ],
            RequisitionResourceType::Cash->value => [
                ...$rules,
                'items.*.estimated_amount' => ['required', 'numeric', 'gt:0'],
            ],
            RequisitionResourceType::Equipment->value => [
                ...$rules,
                'items.*.duration' => ['required', 'numeric', 'gt:0'],
                'items.*.duration_unit' => ['required', 'string', Rule::in(['day', 'hour', 'week'])],
                'items.*.rate' => ['required', 'numeric', 'gte:0'],
            ],
            RequisitionResourceType::Transport->value => [
                ...$rules,
                'items.*.trips' => ['required', 'numeric', 'gt:0'],
                'items.*.cost_per_trip' => ['required', 'numeric', 'gte:0'],
            ],
            default => [
                ...$rules,
                'items.*.unit' => ['nullable', 'string', 'max:50'],
                'items.*.quantity' => ['required', 'numeric', 'gt:0'],
                'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            ],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $resourceType = (string) $this->input('resource_type');
            $addressedTo = (string) $this->input('addressed_to');
            $fulfillmentType = (string) $this->input('fulfillment_type');

            if ($addressedTo === RequisitionAddressedTo::Storekeeper->value) {
                if (! in_array($resourceType, [
                    RequisitionResourceType::Materials->value,
                    RequisitionResourceType::Fuel->value,
                ], true)) {
                    $validator->errors()->add(
                        'addressed_to',
                        'Only materials or fuel requests can be addressed to the storekeeper.'
                    );
                }

                if ($fulfillmentType !== FulfillmentType::StockIssue->value) {
                    $validator->errors()->add(
                        'fulfillment_type',
                        'Storekeeper requests must be fulfilled as a stock issue.'
                    );
                }
            }

            if ($addressedTo === RequisitionAddressedTo::Finance->value
                && $fulfillmentType === FulfillmentType::StockIssue->value) {
                $validator->errors()->add(
                    'fulfillment_type',
                    'Finance requests cannot use stock issue. Choose cash or supplier payment.'
                );
            }

            if (! in_array($resourceType, [
                RequisitionResourceType::Materials->value,
                RequisitionResourceType::Fuel->value,
            ], true)) {
                return;
            }

            foreach ($this->input('items', []) as $index => $item) {
                if (! empty($item['inventory_item_id'])) {
                    continue;
                }

                if (empty($item['description'])) {
                    $validator->errors()->add(
                        "items.{$index}.description",
                        'Provide a description for new items, or pick from the catalog.'
                    );
                }
            }
        });
    }
}
