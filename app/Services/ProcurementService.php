<?php

namespace App\Services;

use App\Enums\GoodsReceiptCondition;
use App\Enums\PurchaseOrderStatus;
use App\Enums\RequisitionStatus;
use App\Models\EquipmentMaintenance;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly RequisitionService $requisitionService,
    ) {}

    public function createPOFromRequisition(
        Requisition $requisition,
        Supplier $supplier,
        array $data,
        User $actor,
    ): array {
        return DB::transaction(function () use ($requisition, $supplier, $data, $actor) {
            $requisition = Requisition::with('boqItem')->lockForUpdate()->findOrFail($requisition->id);

            if (! in_array($requisition->status, [
                RequisitionStatus::Approved,
                RequisitionStatus::Amended,
                RequisitionStatus::PartiallyFulfilled,
            ], true)) {
                throw ValidationException::withMessages([
                    'requisition_id' => 'Only approved requisitions with an available balance can finance a purchase order.',
                ]);
            }

            $items = collect($data['items'])->map(function (array $item): array {
                $quantity = bcadd((string) $item['quantity'], '0', 3);
                $unitPrice = bcadd((string) $item['unit_price'], '0', 2);

                return [
                    'name' => trim($item['name']),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => bcmul($quantity, $unitPrice, 2),
                ];
            });

            $quantity = $items->reduce(
                fn (string $total, array $item) => bcadd($total, $item['quantity'], 3),
                '0.000',
            );
            $totalAmount = $items->reduce(
                fn (string $total, array $item) => bcadd($total, $item['total_amount'], 2),
                '0.00',
            );
            $unitCost = $items->first()['unit_price'];

            if (bccomp($totalAmount, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    'items' => 'The purchase total must be greater than zero.',
                ]);
            }

            $allocatedAmount = bcadd(
                (string) $requisition->purchaseOrders()
                    ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
                    ->sum('total_amount'),
                '0',
                2,
            );
            $approvedAmount = bcadd(
                (string) ($requisition->amended_amount ?? $requisition->original_amount),
                '0',
                2,
            );
            $fulfilledAmount = bcadd((string) $requisition->fulfilled_amount, '0', 2);
            $purchasePayments = bcadd(
                (string) $requisition->purchaseOrderPayments()
                    ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
                    ->sum('amount'),
                '0',
                2,
            );
            $nonPurchaseFulfilled = bccomp($fulfilledAmount, $purchasePayments, 2) === 1
                ? bcsub($fulfilledAmount, $purchasePayments, 2)
                : '0.00';
            $availableAmount = bcsub(
                bcsub($approvedAmount, $nonPurchaseFulfilled, 2),
                $allocatedAmount,
                2,
            );

            if (bccomp($totalAmount, $availableAmount, 2) === 1) {
                throw ValidationException::withMessages([
                    'items' => "Purchase total exceeds the requisition's available balance of {$availableAmount}.",
                ]);
            }

            $variance = null;

            if ($requisition->boqItem) {
                $boqRate = (string) $requisition->boqItem->unit_rate;
                if (bccomp($unitCost, $boqRate, 2) !== 0) {
                    $variance = [
                        'boq_unit_rate' => $boqRate,
                        'po_unit_cost' => $unitCost,
                        'difference' => bcsub($unitCost, $boqRate, 2),
                    ];
                }
            }

            $purchaseOrder = PurchaseOrder::create([
                'requisition_id' => $requisition->id,
                'supplier_id' => $supplier->id,
                'equipment_id' => $data['equipment_id'] ?? null,
                'boq_item_id' => $requisition->boq_item_id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_amount' => $totalAmount,
                'purchase_date' => $data['purchase_date'],
                'status' => PurchaseOrderStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $purchaseOrder->update([
                'purchase_order_no' => 'PO-'.str_pad((string) $purchaseOrder->id, 6, '0', STR_PAD_LEFT),
            ]);
            $purchaseOrder->items()->createMany($items->all());

            if ($purchaseOrder->equipment_id) {
                EquipmentMaintenance::create([
                    'equipment_id' => $purchaseOrder->equipment_id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'type' => 'maintenance',
                    'cost' => $totalAmount,
                    'description' => "Purchase {$purchaseOrder->purchase_order_no}: ".$items->pluck('name')->implode(', '),
                    'date' => $purchaseOrder->purchase_date,
                    'created_at' => now(),
                ]);
            }

            $requisition->boqItem?->increment('procured_qty', $quantity);

            $paymentAmount = bcadd((string) ($data['payment_amount'] ?? 0), '0', 2);
            if (bccomp($paymentAmount, '0', 2) === 1) {
                try {
                    $this->recordPayment($purchaseOrder, $paymentAmount, $actor, [
                        'method' => $data['payment_method'] ?? null,
                        'reference_no' => $data['payment_reference_no'] ?? null,
                        'paid_at' => $data['purchase_date'],
                        'notes' => 'Initial payment recorded when the purchase order was created.',
                    ]);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(
                        $this->paymentErrorsForCreateForm($exception->errors()),
                    );
                }
            }

            return [
                'purchase_order' => $purchaseOrder->load('items')->loadSum('payments', 'amount'),
                'variance' => $variance,
                'approved_amount' => $approvedAmount,
                'allocated_amount' => bcadd($allocatedAmount, $totalAmount, 2),
                'remaining_amount' => bcsub($availableAmount, $totalAmount, 2),
            ];
        });
    }

    public function recordPayment(
        PurchaseOrder $purchaseOrder,
        string $amount,
        User $actor,
        array $data,
    ): PurchaseOrderPayment {
        return DB::transaction(function () use ($purchaseOrder, $amount, $actor, $data) {
            $purchaseOrder = PurchaseOrder::with(['supplier', 'requisition.items'])
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);
            $normalizedAmount = bcadd($amount, '0', 2);
            $paidAmount = bcadd((string) $purchaseOrder->payments()->sum('amount'), '0', 2);
            $outstandingAmount = bcsub((string) $purchaseOrder->total_amount, $paidAmount, 2);

            if (bccomp($normalizedAmount, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            if (bccomp($normalizedAmount, $outstandingAmount, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Payment exceeds the supplier debt of {$outstandingAmount}.",
                ]);
            }

            $method = strtolower(trim((string) ($data['method'] ?? '')));
            $referenceNo = trim((string) ($data['reference_no'] ?? ''));
            if (! in_array($method, ['cash', 'mobile', 'bank'], true)) {
                throw ValidationException::withMessages([
                    'method' => 'Choose payment method: cash, mobile, or bank.',
                ]);
            }
            if ($referenceNo === '') {
                throw ValidationException::withMessages([
                    'reference_no' => 'A payment reference number is required.',
                ]);
            }

            $requisition = $purchaseOrder->requisition;
            $targetAmount = bcadd(
                (string) ($requisition->amended_amount ?? $requisition->original_amount),
                '0',
                2,
            );
            $requisitionRemaining = bcsub(
                $targetAmount,
                (string) $requisition->fulfilled_amount,
                2,
            );
            if (bccomp($normalizedAmount, $requisitionRemaining, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Payment exceeds the requisition's unpaid amount of {$requisitionRemaining}.",
                ]);
            }

            $scope = (string) ($requisition->fulfillment_scope ?? 'whole');
            $transitionOpts = [
                'fulfillment_scope' => $scope,
                'amount' => $normalizedAmount,
                'payee' => $purchaseOrder->supplier->name,
                'account_name' => $purchaseOrder->supplier->name,
                'method' => $method,
                'reference_no' => $referenceNo,
                'comment' => "Supplier payment for {$purchaseOrder->purchase_order_no}.",
            ];

            if ($scope === 'items') {
                $transitionOpts['items'] = $this->allocatePaymentToRequisitionItems(
                    $requisition,
                    $normalizedAmount,
                );
            }

            $this->requisitionService->transition($requisition, 'fulfilled', $actor, $transitionOpts);

            $cashDisbursement = $requisition->cashDisbursements()
                ->where('reference_no', $referenceNo)
                ->latest('id')
                ->first();

            return $purchaseOrder->payments()->create([
                'cash_disbursement_id' => $cashDisbursement?->id,
                'amount' => $normalizedAmount,
                'method' => $method,
                'reference_no' => $referenceNo,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);
        });
    }

    /**
     * Requisitions already fulfilled item-by-item keep tracking progress per line, so a supplier
     * payment has to be expressed as line quantities. Quantities carry three decimals, so the
     * allocation must land exactly on the requested amount before any cash moves.
     *
     * @return array<int, array{requisition_item_id: int, quantity: string}>
     */
    private function allocatePaymentToRequisitionItems(Requisition $requisition, string $amount): array
    {
        $remaining = $amount;
        $allocated = '0.00';
        $items = [];

        foreach ($requisition->items as $item) {
            if (bccomp($remaining, '0', 2) !== 1) {
                break;
            }

            $unitCost = bcadd((string) $item->unit_cost, '0', 2);
            $remainingQuantity = bcsub((string) $item->quantity, (string) $item->fulfilled_quantity, 3);

            if (bccomp($unitCost, '0', 2) !== 1 || bccomp($remainingQuantity, '0', 3) !== 1) {
                continue;
            }

            $lineCapacity = bcmul($remainingQuantity, $unitCost, 2);
            $quantity = bcdiv(
                bccomp($remaining, $lineCapacity, 2) === 1 ? $lineCapacity : $remaining,
                $unitCost,
                3,
            );

            if (bccomp($quantity, '0', 3) !== 1) {
                continue;
            }

            $lineAmount = bcmul($quantity, $unitCost, 2);
            $items[] = [
                'requisition_item_id' => $item->id,
                'quantity' => $quantity,
            ];
            $allocated = bcadd($allocated, $lineAmount, 2);
            $remaining = bcsub($remaining, $lineAmount, 2);
        }

        if ($items === [] || bccomp($allocated, $amount, 2) !== 0) {
            throw ValidationException::withMessages([
                'amount' => "{$requisition->requisition_no} is being fulfilled item by item, so a payment must match its line rates. The closest payable amount is {$allocated}.",
            ]);
        }

        return $items;
    }

    /**
     * Payment failures raised while creating a purchase order have to land on the create form's
     * own field names, otherwise the dialog silently discards them.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private function paymentErrorsForCreateForm(array $errors): array
    {
        $fields = [
            'amount' => 'payment_amount',
            'method' => 'payment_method',
            'reference_no' => 'payment_reference_no',
        ];
        $remapped = [];

        foreach ($errors as $key => $messages) {
            $field = $fields[$key] ?? 'payment_amount';
            $remapped[$field] = array_merge($remapped[$field] ?? [], $messages);
        }

        return $remapped;
    }

    public function recordGoodsReceipt(
        PurchaseOrder $purchaseOrder,
        string $quantityReceived,
        User $receiver,
        array $opts = [],
    ): GoodsReceipt {
        return DB::transaction(function () use ($purchaseOrder, $quantityReceived, $receiver, $opts) {
            $purchaseOrder = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
            $qty = bcadd($quantityReceived, '0', 3);

            $totalReceived = bcadd(
                (string) $purchaseOrder->goodsReceipts()->sum('quantity_received'),
                $qty,
                4
            );

            if (bccomp($totalReceived, (string) $purchaseOrder->quantity, 3) === 1) {
                throw new \InvalidArgumentException('Received quantity exceeds purchase order quantity.');
            }

            $goodsReceipt = GoodsReceipt::create([
                'purchase_order_id' => $purchaseOrder->id,
                'quantity_received' => $qty,
                'condition' => $opts['condition'] ?? GoodsReceiptCondition::Good,
                'received_by' => $receiver->id,
                'received_at' => now(),
                'created_at' => now(),
            ]);

            $purchaseOrder->boqItem?->increment('received_qty', $qty);

            $newStatus = bccomp($totalReceived, (string) $purchaseOrder->quantity, 3) === 0
                ? PurchaseOrderStatus::FullyReceived
                : PurchaseOrderStatus::PartiallyReceived;

            $purchaseOrder->update(['status' => $newStatus]);

            if (! empty($opts['inventory_item_id']) && ! empty($opts['stock_location_id'])) {
                $this->inventoryService->receive(
                    (int) $opts['inventory_item_id'],
                    (int) $opts['stock_location_id'],
                    $qty,
                    $receiver,
                    bcadd((string) $purchaseOrder->unit_cost, '0', 2),
                    [
                        'reference_entity_type' => 'goods_receipt',
                        'reference_entity_id' => $goodsReceipt->id,
                    ]
                );
            }

            return $goodsReceipt;
        });
    }
}
