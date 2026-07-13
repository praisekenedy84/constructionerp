<?php

namespace App\Services;

use App\Enums\GoodsReceiptCondition;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function createPOFromRequisition(
        Requisition $requisition,
        Supplier $supplier,
        array $opts = [],
    ): array {
        return DB::transaction(function () use ($requisition, $supplier, $opts) {
            $requisition->load('items', 'boqItem');

            $quantity = $opts['quantity'] ?? $requisition->items->sum('quantity');
            $quantity = bcadd((string) $quantity, '0', 4);
            $unitCost = bcadd(
                (string) ($opts['unit_cost'] ?? $requisition->items->first()?->unit_cost ?? '0'),
                '0',
                2
            );
            $totalAmount = bcmul($quantity, $unitCost, 2);

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
                'boq_item_id' => $requisition->boq_item_id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_amount' => $totalAmount,
                'status' => PurchaseOrderStatus::Draft,
            ]);

            $requisition->boqItem?->increment('procured_qty', $quantity);

            return [
                'purchase_order' => $purchaseOrder,
                'variance' => $variance,
            ];
        });
    }

    public function recordGoodsReceipt(
        PurchaseOrder $purchaseOrder,
        string $quantityReceived,
        User $receiver,
        array $opts = [],
    ): GoodsReceipt {
        return DB::transaction(function () use ($purchaseOrder, $quantityReceived, $receiver, $opts) {
            $purchaseOrder = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
            $qty = bcadd($quantityReceived, '0', 4);

            $totalReceived = bcadd(
                (string) $purchaseOrder->goodsReceipts()->sum('quantity_received'),
                $qty,
                4
            );

            if (bccomp($totalReceived, (string) $purchaseOrder->quantity, 4) === 1) {
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

            $newStatus = bccomp($totalReceived, (string) $purchaseOrder->quantity, 4) === 0
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
