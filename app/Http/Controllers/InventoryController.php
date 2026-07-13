<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryAdjustRequest;
use App\Http\Requests\InventoryIssueRequest;
use App\Http\Requests\InventoryTransferRequest;
use App\Models\InventoryIssue;
use App\Models\InventoryTransaction;
use App\Models\StockBalance;
use App\Services\InventoryService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService) {}

    public function items(Request $request): RedirectResponse
    {
        return redirect()->route('inventory.balances');
    }

    public function balances(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Storekeeper']);

        $query = StockBalance::query()->with(['inventoryItem', 'stockLocation']);

        if ($request->filled('project_id')) {
            $query->whereHas('stockLocation', fn ($q) => $q->where('project_id', $request->integer('project_id')));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['inventoryItem.name', 'inventoryItem.code', 'stockLocation.name'])
            ->dateRange('updated_at')
            ->sort(['updated_at', 'quantity_on_hand', 'average_cost'], 'updated_at');

        $balances = $listing->paginate(25);

        $lowStockCount = \App\Models\InventoryItem::query()
            ->whereNotNull('reorder_point')
            ->with('stockBalances')
            ->get()
            ->filter(function (\App\Models\InventoryItem $item) {
                $onHand = $item->stockBalances->sum(fn ($b) => (float) $b->quantity_on_hand);

                return $onHand <= (float) $item->reorder_point;
            })
            ->count();

        return Inertia::render('Inventory/Stock', [
            'balances' => $balances,
            'low_stock_count' => $lowStockCount,
            'filters' => $listing->filters(['project_id' => $request->input('project_id')]),
        ]);
    }

    public function issues(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Storekeeper']);

        $listing = ListingQuery::for(
            InventoryIssue::query()->with(['inventoryItem', 'stockLocation']),
            $request,
        )
            ->search(['work_section', 'inventoryItem.name', 'inventoryItem.code', 'stockLocation.name'])
            ->dateRange('issued_at')
            ->sort(['issued_at', 'quantity', 'value'], 'issued_at');

        return Inertia::render('Inventory/Issues', [
            'issues' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function transactions(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Storekeeper']);

        $listing = ListingQuery::for(
            InventoryTransaction::query()->with(['inventoryItem', 'stockLocation']),
            $request,
        )
            ->search(['type', 'inventoryItem.name', 'inventoryItem.code', 'stockLocation.name'])
            ->dateRange('created_at')
            ->sort(['created_at', 'quantity', 'unit_cost', 'type']);

        return Inertia::render('Inventory/Transactions', [
            'transactions' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function issue(InventoryIssueRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Storekeeper']);

        $validated = $request->validated();
        $this->inventoryService->issue(
            (int) $validated['inventory_item_id'],
            (int) $validated['stock_location_id'],
            (string) $validated['quantity'],
            $request->user(),
            [
                'requisition_id' => $validated['requisition_id'] ?? null,
                'recipient_id' => $validated['recipient_id'],
                'work_section' => $validated['work_section'] ?? null,
            ],
        );

        return back()->with('success', 'Stock issued.');
    }

    public function transfer(InventoryTransferRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Storekeeper']);

        $validated = $request->validated();
        $this->inventoryService->transfer(
            (int) $validated['inventory_item_id'],
            (int) $validated['from_location_id'],
            (int) $validated['to_location_id'],
            (string) $validated['quantity'],
            $request->user(),
        );

        return back()->with('success', 'Stock transferred.');
    }

    public function adjust(InventoryAdjustRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Storekeeper', 'Finance Manager']);

        $validated = $request->validated();
        $this->inventoryService->adjust(
            (int) $validated['inventory_item_id'],
            (int) $validated['stock_location_id'],
            (string) $validated['new_quantity'],
            $request->user(),
        );

        return back()->with('success', 'Stock adjustment recorded.');
    }
}
