<?php

namespace App\Services;

use App\Enums\InventoryTransactionType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Notification;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function issue(
        int $inventoryItemId,
        int $stockLocationId,
        string $quantity,
        User $actor,
        array $opts = [],
    ): InventoryIssue {
        return DB::transaction(function () use ($inventoryItemId, $stockLocationId, $quantity, $actor, $opts) {
            $qty = bcadd($quantity, '0', 4);
            $balance = $this->lockBalance($inventoryItemId, $stockLocationId);

            if (bccomp((string) $balance->quantity_on_hand, $qty, 4) < 0) {
                throw new InsufficientStockException(
                    $inventoryItemId,
                    $stockLocationId,
                    $qty,
                    (string) $balance->quantity_on_hand
                );
            }

            $balance->decrement('quantity_on_hand', $qty);
            $balance->update(['updated_at' => now()]);

            $unitCost = $opts['unit_cost'] ?? (string) $balance->average_cost;
            $value = bcmul($qty, $unitCost, 2);

            InventoryTransaction::create([
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
                'type' => InventoryTransactionType::Out,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'reference_entity_type' => $opts['reference_entity_type'] ?? 'inventory_issue',
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            return InventoryIssue::create([
                'requisition_id' => $opts['requisition_id'] ?? null,
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
                'quantity' => $qty,
                'recipient_id' => $opts['recipient_id'] ?? $actor->id,
                'work_section' => $opts['work_section'] ?? null,
                'value' => $value,
                'issued_at' => now(),
                'created_at' => now(),
            ]);
        });
    }

    public function receive(
        int $inventoryItemId,
        int $stockLocationId,
        string $quantity,
        User $actor,
        string $unitCost,
        array $opts = [],
    ): InventoryTransaction {
        return DB::transaction(function () use ($inventoryItemId, $stockLocationId, $quantity, $actor, $unitCost, $opts) {
            $qty = bcadd($quantity, '0', 4);
            $cost = bcadd($unitCost, '0', 2);
            $balance = $this->lockBalance($inventoryItemId, $stockLocationId);

            $oldQty = (string) $balance->quantity_on_hand;
            $oldCost = (string) $balance->average_cost;
            $newQty = bcadd($oldQty, $qty, 4);

            if (bccomp($newQty, '0', 4) === 1) {
                $totalValue = bcadd(bcmul($oldQty, $oldCost, 2), bcmul($qty, $cost, 2), 2);
                $balance->average_cost = bcdiv($totalValue, $newQty, 2);
            }

            $balance->quantity_on_hand = $newQty;
            $balance->updated_at = now();
            $balance->save();

            return InventoryTransaction::create([
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
                'type' => InventoryTransactionType::In,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'reference_entity_type' => $opts['reference_entity_type'] ?? null,
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        });
    }

    public function transfer(
        int $inventoryItemId,
        int $fromLocationId,
        int $toLocationId,
        string $quantity,
        User $actor,
        array $opts = [],
    ): array {
        return DB::transaction(function () use ($inventoryItemId, $fromLocationId, $toLocationId, $quantity, $actor) {
            $qty = bcadd($quantity, '0', 4);
            $source = $this->lockBalance($inventoryItemId, $fromLocationId);

            if (bccomp((string) $source->quantity_on_hand, $qty, 4) < 0) {
                throw new InsufficientStockException(
                    $inventoryItemId,
                    $fromLocationId,
                    $qty,
                    (string) $source->quantity_on_hand
                );
            }

            $unitCost = (string) $source->average_cost;

            $source->decrement('quantity_on_hand', $qty);
            $source->update(['updated_at' => now()]);

            $outTxn = InventoryTransaction::create([
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $fromLocationId,
                'type' => InventoryTransactionType::Transfer,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'reference_entity_type' => 'transfer_out',
                'reference_entity_id' => $toLocationId,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            $inTxn = $this->receive(
                $inventoryItemId,
                $toLocationId,
                $qty,
                $actor,
                $unitCost,
                [
                    'reference_entity_type' => 'transfer_in',
                    'reference_entity_id' => $fromLocationId,
                ]
            );

            return ['out' => $outTxn, 'in' => $inTxn];
        });
    }

    public function returnStock(
        int $inventoryItemId,
        int $stockLocationId,
        string $quantity,
        User $actor,
        string $unitCost,
        array $opts = [],
    ): InventoryTransaction {
        return DB::transaction(function () use ($inventoryItemId, $stockLocationId, $quantity, $actor, $unitCost, $opts) {
            $qty = bcadd($quantity, '0', 4);
            $cost = bcadd($unitCost, '0', 2);
            $balance = $this->lockBalance($inventoryItemId, $stockLocationId);

            $oldQty = (string) $balance->quantity_on_hand;
            $oldCost = (string) $balance->average_cost;
            $newQty = bcadd($oldQty, $qty, 4);

            if (bccomp($newQty, '0', 4) === 1) {
                $totalValue = bcadd(bcmul($oldQty, $oldCost, 2), bcmul($qty, $cost, 2), 2);
                $balance->average_cost = bcdiv($totalValue, $newQty, 2);
            }

            $balance->quantity_on_hand = $newQty;
            $balance->updated_at = now();
            $balance->save();

            return InventoryTransaction::create([
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
                'type' => InventoryTransactionType::Return,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'reference_entity_type' => $opts['reference_entity_type'] ?? null,
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        });
    }

    public function damage(
        int $inventoryItemId,
        int $stockLocationId,
        string $quantity,
        User $actor,
        array $opts = [],
    ): InventoryTransaction {
        return DB::transaction(function () use ($inventoryItemId, $stockLocationId, $quantity, $actor, $opts) {
            $qty = bcadd($quantity, '0', 4);
            $balance = $this->lockBalance($inventoryItemId, $stockLocationId);

            if (bccomp((string) $balance->quantity_on_hand, $qty, 4) < 0) {
                throw new InsufficientStockException(
                    $inventoryItemId,
                    $stockLocationId,
                    $qty,
                    (string) $balance->quantity_on_hand
                );
            }

            $balance->decrement('quantity_on_hand', $qty);
            $balance->update(['updated_at' => now()]);

            return InventoryTransaction::create([
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
                'type' => InventoryTransactionType::Damage,
                'quantity' => $qty,
                'unit_cost' => (string) $balance->average_cost,
                'reference_entity_type' => $opts['reference_entity_type'] ?? null,
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        });
    }

    public function adjust(
        int $inventoryItemId,
        int $stockLocationId,
        string $newQuantity,
        User $actor,
        array $opts = [],
    ): InventoryTransaction {
        return DB::transaction(function () use ($inventoryItemId, $stockLocationId, $newQuantity, $actor, $opts) {
            $targetQty = bcadd($newQuantity, '0', 4);
            $balance = $this->lockBalance($inventoryItemId, $stockLocationId);
            $currentQty = (string) $balance->quantity_on_hand;

            if (bccomp($targetQty, '0', 4) < 0) {
                throw new InsufficientStockException(
                    $inventoryItemId,
                    $stockLocationId,
                    bcsub('0', $targetQty, 4),
                    $currentQty
                );
            }

            $difference = bcsub($targetQty, $currentQty, 4);

            if (bccomp($difference, '0', 4) === 0) {
                throw new \InvalidArgumentException('Adjustment quantity matches current stock.');
            }

            if (bccomp($difference, '0', 4) === 1) {
                $this->receive(
                    $inventoryItemId,
                    $stockLocationId,
                    $difference,
                    $actor,
                    $opts['unit_cost'] ?? (string) $balance->average_cost,
                    $opts
                );
            } else {
                $outQty = ltrim($difference, '-');
                if (bccomp($currentQty, $outQty, 4) < 0) {
                    throw new InsufficientStockException(
                        $inventoryItemId,
                        $stockLocationId,
                        $outQty,
                        $currentQty
                    );
                }
                $balance->decrement('quantity_on_hand', $outQty);
                $balance->update(['updated_at' => now()]);
            }

            return InventoryTransaction::create([
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
                'type' => InventoryTransactionType::Adjustment,
                'quantity' => $difference,
                'unit_cost' => (string) $balance->fresh()->average_cost,
                'reference_entity_type' => $opts['reference_entity_type'] ?? null,
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }        );
    }

    public function checkLowStock(): void
    {
        $lowItems = InventoryItem::query()
            ->whereNotNull('reorder_point')
            ->with('stockBalances')
            ->get()
            ->filter(function (InventoryItem $item) {
                $onHand = $item->stockBalances->sum(fn ($b) => (float) $b->quantity_on_hand);

                return $onHand <= (float) $item->reorder_point;
            });

        $storekeepers = User::role('Storekeeper')->get();

        foreach ($lowItems as $item) {
            foreach ($storekeepers as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'low_stock',
                    'data' => [
                        'inventory_item_id' => $item->id,
                        'code' => $item->code,
                        'name' => $item->name,
                    ],
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem>
     */
    public function items(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\InventoryItem::query()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{
     *     balances: \Illuminate\Database\Eloquent\Collection<int, StockBalance>,
     *     low_stock_count: int,
     * }
     */
    public function balances(array $filters = []): array
    {
        $balances = StockBalance::query()
            ->with(['inventoryItem', 'stockLocation'])
            ->when($filters['project_id'] ?? null, function ($query, $projectId) {
                $query->whereHas('stockLocation', fn ($q) => $q->where('project_id', $projectId));
            })
            ->orderBy('inventory_item_id')
            ->get();

        $lowStockCount = \App\Models\InventoryItem::query()
            ->whereNotNull('reorder_point')
            ->with('stockBalances')
            ->get()
            ->filter(function (\App\Models\InventoryItem $item) {
                $onHand = $item->stockBalances->sum(fn ($b) => (float) $b->quantity_on_hand);

                return $onHand <= (float) $item->reorder_point;
            })
            ->count();

        return [
            'balances' => $balances,
            'low_stock_count' => $lowStockCount,
        ];
    }

    private function lockBalance(int $inventoryItemId, int $stockLocationId): StockBalance
    {
        return StockBalance::lockForUpdate()->firstOrCreate(
            [
                'inventory_item_id' => $inventoryItemId,
                'stock_location_id' => $stockLocationId,
            ],
            [
                'quantity_on_hand' => '0',
                'average_cost' => '0',
                'updated_at' => now(),
            ]
        );
    }
}
