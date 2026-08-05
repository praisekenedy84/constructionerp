<?php

namespace App\Http\Requests;

use App\Enums\FulfillmentType;
use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransitionRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'to_status' => ['required', Rule::enum(RequisitionStatus::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
            'amendment_reason' => ['nullable', 'string', 'max:2000'],
            'amended_amount' => ['nullable', 'numeric', 'gt:0'],
            'override' => ['nullable', 'boolean'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'stock_location_id' => ['nullable', 'integer', 'exists:stock_locations,id'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'work_section' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', Rule::in(['cash', 'mobile', 'bank'])],
            'payee' => ['nullable', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'cash_allocation_id' => ['nullable', 'integer', 'exists:cash_allocations,id'],
            'fulfillment_scope' => ['nullable', 'string', Rule::in(['whole', 'items'])],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'items' => ['nullable', 'array'],
            'items.*.requisition_item_id' => ['required_with:items', 'integer', 'exists:requisition_items,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'items.*.stock_location_id' => ['nullable', 'integer', 'exists:stock_locations,id'],
            'inventory_source' => ['nullable', 'string', 'in:existing,new'],
            'new_inventory_item' => ['nullable', 'array'],
            'new_inventory_item.name' => ['required_if:inventory_source,new', 'nullable', 'string', 'max:255'],
            'new_inventory_item.unit' => ['required_if:inventory_source,new', 'nullable', 'string', 'max:50'],
            'new_inventory_item.category' => ['required_if:inventory_source,new', 'nullable', 'string', 'max:50'],
            'new_inventory_item.code' => ['nullable', 'string', 'max:50'],
            'new_inventory_item.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'new_inventory_item.receive_quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('to_status') !== 'fulfilled') {
                return;
            }

            $requisition = Requisition::find($this->route('id'));
            if (! $requisition) {
                return;
            }

            $type = $requisition->fulfillment_type instanceof FulfillmentType
                ? $requisition->fulfillment_type->value
                : (string) $requisition->fulfillment_type;

            $isFinance = $requisition->addressed_to?->value === 'finance'
                || (
                    $requisition->addressed_to === null
                    && in_array($type, ['cash_disbursement', 'direct_supplier_payment'], true)
                );

            if (! $isFinance) {
                return;
            }

            if (! filled($this->input('payee')) && ! filled($this->input('account_name'))) {
                $validator->errors()->add('payee', 'Enter the account or party that received the cash.');
            }

            if (! filled($this->input('reference_no'))) {
                $validator->errors()->add('reference_no', 'A disbursement reference number is required.');
            }

            if (! in_array($this->input('method'), ['cash', 'mobile', 'bank'], true)) {
                $validator->errors()->add('method', 'Choose payment method: cash, mobile, or bank.');
            }
        });
    }
}
