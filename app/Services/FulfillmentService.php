<?php

namespace App\Services;

use App\Enums\CashAllocationStatus;
use App\Enums\InventoryItemCategory;
use App\Exceptions\InsufficientCashException;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\InventoryItem;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillmentService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function fulfillCash(Requisition $req, User $actor, string $amount, array $opts = []): CashDisbursement
    {
        return DB::transaction(function () use ($req, $actor, $amount, $opts) {
            $normalizedAmount = bcadd($amount, '0', 2);

            $payee = trim((string) ($opts['payee'] ?? $opts['account_name'] ?? ''));
            $accountName = trim((string) ($opts['account_name'] ?? $payee));
            $referenceNo = trim((string) ($opts['reference_no'] ?? ''));
            $method = strtolower(trim((string) ($opts['method'] ?? '')));

            if ($payee === '' && $accountName === '') {
                throw ValidationException::withMessages([
                    'payee' => 'Enter the account or party that received the cash.',
                ]);
            }

            if ($referenceNo === '') {
                throw ValidationException::withMessages([
                    'reference_no' => 'A disbursement reference number is required.',
                ]);
            }

            if (! in_array($method, ['cash', 'mobile', 'bank'], true)) {
                throw ValidationException::withMessages([
                    'method' => 'Choose payment method: cash, mobile, or bank.',
                ]);
            }

            $allocation = isset($opts['cash_allocation_id'])
                ? CashAllocation::lockForUpdate()->findOrFail($opts['cash_allocation_id'])
                : CashAllocation::query()
                    ->when(
                        $req->isOrganizationWide(),
                        fn ($q) => $q->whereNull('project_id'),
                        fn ($q) => $q->where('project_id', $req->project_id),
                    )
                    ->where('status', CashAllocationStatus::Received)
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (CashAllocation $a) => bccomp($a->balance, $normalizedAmount, 2) >= 0);

            if (! $allocation) {
                throw new InsufficientCashException($normalizedAmount, '0');
            }

            if ($req->isOrganizationWide()) {
                if (! $allocation->isOrganizationWide()) {
                    throw ValidationException::withMessages([
                        'cash_allocation_id' => 'Organization requisitions must be paid from organization cash on hand, not a project float.',
                    ]);
                }
            } elseif ($allocation->isOrganizationWide() || (int) $allocation->project_id !== (int) $req->project_id) {
                throw ValidationException::withMessages([
                    'cash_allocation_id' => 'Project requisitions must be paid from that project’s cash float, not organization funds.',
                ]);
            }

            if ($allocation->status !== CashAllocationStatus::Received) {
                throw ValidationException::withMessages([
                    'cash_allocation_id' => 'Only received cash floats can be disbursed.',
                ]);
            }

            if (bccomp($allocation->balance, $normalizedAmount, 2) < 0) {
                throw new InsufficientCashException($normalizedAmount, $allocation->balance);
            }

            return CashDisbursement::create([
                'requisition_id' => $req->id,
                'cash_allocation_id' => $allocation->id,
                'amount' => $normalizedAmount,
                'method' => $method,
                'payee' => $payee !== '' ? $payee : $accountName,
                'account_name' => $accountName !== '' ? $accountName : $payee,
                'reference_no' => $referenceNo,
                'disbursed_by' => $actor->id,
                'disbursed_at' => now(),
                'created_at' => now(),
            ]);
        });
    }

    public function fulfillStock(Requisition $req, User $actor, array $opts = []): array
    {
        return DB::transaction(function () use ($req, $actor, $opts) {
            $issues = $opts['issues'] ?? [];
            $results = [];

            if (($opts['inventory_source'] ?? 'existing') === 'new') {
                $inventoryItemId = $this->createInventoryItemForFulfillment($req, $actor, $opts);
                $opts['inventory_item_id'] = $inventoryItemId;
            }

            if (empty($issues)) {
                foreach ($req->items as $item) {
                    $inventoryItemId = (int) ($opts['inventory_item_id'] ?? $item->inventory_item_id ?? 0);
                    $stockLocationId = (int) ($opts['stock_location_id'] ?? 0);

                    if ($inventoryItemId < 1 || $stockLocationId < 1) {
                        throw new \InvalidArgumentException(
                            'Stock fulfillment requires an inventory item and stock location, or a new item to create.'
                        );
                    }

                    if (! $item->inventory_item_id) {
                        $item->update(['inventory_item_id' => $inventoryItemId]);
                    }

                    $results[] = $this->inventoryService->issue(
                        $inventoryItemId,
                        $stockLocationId,
                        (string) $item->quantity,
                        $actor,
                        [
                            'requisition_id' => $req->id,
                            'recipient_id' => $opts['recipient_id'] ?? $req->requestor_id,
                            'work_section' => $opts['work_section'] ?? null,
                            'unit_cost' => (string) $item->unit_cost,
                        ]
                    );
                }
            } else {
                foreach ($issues as $issue) {
                    $results[] = $this->inventoryService->issue(
                        (int) $issue['inventory_item_id'],
                        (int) $issue['stock_location_id'],
                        bcadd((string) $issue['quantity'], '0', 3),
                        $actor,
                        [
                            'requisition_id' => $req->id,
                            'recipient_id' => $issue['recipient_id'] ?? $req->requestor_id,
                            'work_section' => $issue['work_section'] ?? null,
                            'unit_cost' => isset($issue['unit_cost'])
                                ? bcadd((string) $issue['unit_cost'], '0', 2)
                                : null,
                        ]
                    );
                }
            }

            return $results;
        });
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function createInventoryItemForFulfillment(Requisition $req, User $actor, array $opts): int
    {
        $newItem = $opts['new_inventory_item'] ?? null;
        if (! is_array($newItem) || blank($newItem['name'] ?? null) || blank($newItem['unit'] ?? null)) {
            throw ValidationException::withMessages([
                'new_inventory_item.name' => 'Name and unit are required to create an inventory item.',
            ]);
        }

        $stockLocationId = (int) ($opts['stock_location_id'] ?? 0);
        if ($stockLocationId < 1) {
            throw ValidationException::withMessages([
                'stock_location_id' => 'Choose a stock location for the new inventory item.',
            ]);
        }

        $category = (string) ($newItem['category'] ?? InventoryItemCategory::Materials->value);
        if (! InventoryItemCategory::tryFrom($category)) {
            throw ValidationException::withMessages([
                'new_inventory_item.category' => 'Invalid inventory category.',
            ]);
        }

        $name = trim((string) $newItem['name']);
        $code = trim((string) ($newItem['code'] ?? ''));
        if ($code === '') {
            $code = InventoryItem::generateUniqueCode($name);
        } else {
            $code = strtoupper($code);
            if (InventoryItem::withTrashed()->where('code', $code)->exists()) {
                throw ValidationException::withMessages([
                    'new_inventory_item.code' => 'That inventory code is already in use.',
                ]);
            }
        }

        $item = InventoryItem::create([
            'code' => $code,
            'name' => $name,
            'unit' => trim((string) $newItem['unit']),
            'category' => $category,
        ]);

        $receiveQty = isset($newItem['receive_quantity']) && $newItem['receive_quantity'] !== ''
            ? bcadd((string) $newItem['receive_quantity'], '0', 3)
            : $this->sumIssueQuantity($req, $opts);

        $unitCost = bcadd((string) ($newItem['unit_cost'] ?? $req->items->first()?->unit_cost ?? '0'), '0', 2);

        if (bccomp($receiveQty, '0', 3) === 1) {
            $this->inventoryService->receive(
                $item->id,
                $stockLocationId,
                $receiveQty,
                $actor,
                $unitCost,
                [
                    'reference_entity_type' => 'requisition_fulfillment',
                    'reference_entity_id' => $req->id,
                ]
            );
        }

        $req->items()
            ->whereNull('inventory_item_id')
            ->update(['inventory_item_id' => $item->id]);

        return $item->id;
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function sumIssueQuantity(Requisition $req, array $opts): string
    {
        if (! empty($opts['issues']) && is_array($opts['issues'])) {
            $total = '0';
            foreach ($opts['issues'] as $issue) {
                $total = bcadd($total, (string) ($issue['quantity'] ?? '0'), 3);
            }

            return $total;
        }

        return $req->items->reduce(
            fn (string $carry, RequisitionItem $item) => bcadd($carry, (string) $item->quantity, 3),
            '0'
        );
    }
}
