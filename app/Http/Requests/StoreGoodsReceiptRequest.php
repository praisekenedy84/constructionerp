<?php

namespace App\Http\Requests;

use App\Enums\GoodsReceiptCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'quantity_received' => ['required', 'numeric', 'gt:0'],
            'condition' => ['required', Rule::enum(GoodsReceiptCondition::class)],
        ];
    }
}
