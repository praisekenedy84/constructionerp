<?php

namespace App\Services;

use App\Enums\CashAllocationStatus;
use App\Exceptions\InsufficientCashException;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FulfillmentService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function fulfillCash(Requisition $req, User $actor, string $amount, array $opts = []): CashDisbursement
    {
        return DB::transaction(function () use ($req, $actor, $amount, $opts) {
            $normalizedAmount = bcadd($amount, '0', 2);

            $allocation = isset($opts['cash_allocation_id'])
                ? CashAllocation::lockForUpdate()->findOrFail($opts['cash_allocation_id'])
                : CashAllocation::where('project_id', $req->project_id)
                    ->where('status', CashAllocationStatus::Received)
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (CashAllocation $a) => bccomp($a->balance, $normalizedAmount, 2) >= 0);

            if (! $allocation) {
                throw new InsufficientCashException($normalizedAmount, '0');
            }

            if (bccomp($allocation->balance, $normalizedAmount, 2) < 0) {
                throw new InsufficientCashException($normalizedAmount, $allocation->balance);
            }

            $allocation->increment('utilized_amount', $normalizedAmount);

            return CashDisbursement::create([
                'requisition_id' => $req->id,
                'cash_allocation_id' => $allocation->id,
                'amount' => $normalizedAmount,
                'method' => $opts['method'] ?? $allocation->method ?? 'cash',
                'payee' => $opts['payee'] ?? null,
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

            if (empty($issues)) {
                foreach ($req->items as $item) {
                    if (empty($opts['stock_location_id']) || empty($opts['inventory_item_id'])) {
                        throw new \InvalidArgumentException(
                            'Stock fulfillment requires issues array or stock_location_id and inventory_item_id.'
                        );
                    }

                    $results[] = $this->inventoryService->issue(
                        (int) $opts['inventory_item_id'],
                        (int) $opts['stock_location_id'],
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
                        bcadd((string) $issue['quantity'], '0', 4),
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
}
