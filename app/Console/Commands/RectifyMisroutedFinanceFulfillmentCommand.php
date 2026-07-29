<?php

namespace App\Console\Commands;

use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use App\Models\InventoryIssue;
use App\Models\InventoryTransaction;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FulfillmentService;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RectifyMisroutedFinanceFulfillmentCommand extends Command
{
    protected $signature = 'requisitions:rectify-finance-fulfillment
                            {requisition_no=REQ-2026-00002 : Requisition number to correct}
                            {--payee=Finance correction : Payee on the cash receipt}
                            {--reference=CORR-FINANCE : Reference number on the cash receipt}
                            {--method=cash : Payment method (cash|mobile|bank)}';

    protected $description = 'Convert a wrongly stock-fulfilled finance request into a cash disbursement';

    public function handle(InventoryService $inventoryService, FulfillmentService $fulfillmentService): int
    {
        $requisitionNo = (string) $this->argument('requisition_no');
        $fixed = false;

        Tenant::all()->each(function (Tenant $tenant) use (
            $requisitionNo,
            $inventoryService,
            $fulfillmentService,
            &$fixed,
        ) {
            $tenant->run(function () use (
                $requisitionNo,
                $inventoryService,
                $fulfillmentService,
                $tenant,
                &$fixed,
            ) {
                $req = Requisition::with(['inventoryIssues', 'cashDisbursements', 'project', 'items'])
                    ->where('requisition_no', $requisitionNo)
                    ->first();

                if (! $req) {
                    return;
                }

                $this->info("Found {$requisitionNo} in tenant {$tenant->id} ({$req->project?->code})");

                $payee = (string) $this->option('payee');
                $reference = (string) $this->option('reference');
                $method = (string) $this->option('method');

                DB::transaction(function () use (
                    $req,
                    $inventoryService,
                    $fulfillmentService,
                    $payee,
                    $reference,
                    $method,
                ) {
                    $actor = User::query()->orderBy('id')->firstOrFail();

                    foreach ($req->inventoryIssues as $issue) {
                        /** @var InventoryIssue $issue */
                        $unitCost = bccomp((string) $issue->quantity, '0', 3) === 1
                            ? bcdiv((string) $issue->value, (string) $issue->quantity, 2)
                            : '0';

                        $inventoryService->returnStock(
                            (int) $issue->inventory_item_id,
                            (int) $issue->stock_location_id,
                            (string) $issue->quantity,
                            $actor,
                            $unitCost,
                            [
                                'reference_entity_type' => 'requisition_correction',
                                'reference_entity_id' => $req->id,
                            ],
                        );

                        InventoryTransaction::query()
                            ->where('inventory_item_id', $issue->inventory_item_id)
                            ->where('stock_location_id', $issue->stock_location_id)
                            ->where('reference_entity_type', 'inventory_issue')
                            ->where('created_at', $issue->created_at)
                            ->delete();

                        $issue->delete();
                    }

                    $req->update([
                        'addressed_to' => RequisitionAddressedTo::Finance,
                        'fulfillment_type' => FulfillmentType::CashDisbursement,
                    ]);

                    if ($req->cashDisbursements->isEmpty()) {
                        $amount = (string) ($req->amended_amount ?? $req->original_amount);
                        $fulfillmentService->fulfillCash($req->fresh(['items']), $actor, $amount, [
                            'payee' => $payee,
                            'account_name' => $payee,
                            'reference_no' => $reference,
                            'method' => $method,
                        ]);
                    }
                });

                $req->refresh()->load('cashDisbursements');
                $disbursed = (string) $req->cashDisbursements->sum('amount');
                $this->info("Corrected: addressed_to=finance, cash disbursed={$disbursed}");
                $fixed = true;
            });
        });

        if (! $fixed) {
            $this->error("Requisition {$requisitionNo} was not found.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
