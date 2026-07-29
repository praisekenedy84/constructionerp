<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryAdjustRequest;
use App\Http\Requests\InventoryIssueRequest;
use App\Http\Requests\InventoryReceiveRequest;
use App\Http\Requests\InventoryTransferRequest;
use App\Http\Requests\StoreInventoryItemRequest;
use App\Http\Requests\StoreStockLocationRequest;
use App\Enums\InventoryItemCategory;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Project;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService) {}

    public function items(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'inventory', 'read');

        $listing = ListingQuery::for(InventoryItem::query(), $request)
            ->search(['code', 'name', 'unit', 'category'])
            ->dateRange('created_at')
            ->sort(['name', 'code', 'category', 'created_at'], 'name');

        return Inertia::render('Inventory/Items', [
            'items' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'categories' => collect(InventoryItemCategory::cases())->map(fn (InventoryItemCategory $category) => [
                'value' => $category->value,
                'label' => str($category->value)->replace('_', ' ')->title()->toString(),
            ]),
            'stock_locations' => StockLocation::query()->orderBy('name')->get(['id', 'name', 'project_id']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeItem(StoreInventoryItemRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'inventory', 'create');

        $validated = $request->validated();
        $openingQuantity = $validated['opening_quantity'] ?? null;
        $stockLocationId = $validated['stock_location_id'] ?? null;
        $unitCost = (string) ($validated['unit_cost'] ?? '0');

        unset($validated['opening_quantity'], $validated['stock_location_id'], $validated['unit_cost']);

        DB::transaction(function () use ($validated, $openingQuantity, $stockLocationId, $unitCost, $request) {
            $item = InventoryItem::create($validated);

            if ($openingQuantity !== null && $stockLocationId !== null) {
                $this->inventoryService->receive(
                    $item->id,
                    (int) $stockLocationId,
                    (string) $openingQuantity,
                    $request->user(),
                    $unitCost,
                    [
                        'reference_entity_type' => 'opening_stock',
                        'reference_entity_id' => $item->id,
                    ],
                );
            }
        });

        $message = $openingQuantity !== null
            ? 'Item created and opening stock recorded.'
            : 'Item created. Add stock from On Hand when you are ready.';

        return back()->with('success', $message);
    }

    public function storeLocation(StoreStockLocationRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'inventory', 'create');

        StockLocation::create($request->validated());

        return back()->with('success', 'Stock location created.');
    }

    public function balances(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'inventory', 'read');

        $query = StockBalance::query()->with(['inventoryItem', 'stockLocation']);

        if ($request->filled('project_id')) {
            $query->whereHas('stockLocation', fn ($q) => $q->where('project_id', $request->integer('project_id')));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['inventoryItem.name', 'inventoryItem.code', 'stockLocation.name'])
            ->dateRange('updated_at')
            ->sort(['updated_at', 'quantity_on_hand', 'average_cost'], 'updated_at');

        $balances = $listing->paginate(25);

        $lowStockCount = InventoryItem::query()
            ->whereNotNull('reorder_point')
            ->with('stockBalances')
            ->get()
            ->filter(function (InventoryItem $item) {
                $onHand = $item->stockBalances->sum(fn ($b) => (float) $b->quantity_on_hand);

                return $onHand <= (float) $item->reorder_point;
            })
            ->count();

        return Inertia::render('Inventory/Stock', [
            'balances' => $balances,
            'low_stock_count' => $lowStockCount,
            'filters' => $listing->filters(['project_id' => $request->input('project_id')]),
            ...$this->formOptions(),
        ]);
    }

    public function issues(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'inventory', 'read');

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
            ...$this->formOptions(),
        ]);
    }

    public function transactions(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'inventory', 'read');

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
        $this->authorizePermission($request->user(), 'inventory', 'issue');

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
        $this->authorizePermission($request->user(), 'inventory', 'issue');

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
        $this->authorizePermission($request->user(), 'inventory', 'adjust');

        $validated = $request->validated();
        $this->inventoryService->adjust(
            (int) $validated['inventory_item_id'],
            (int) $validated['stock_location_id'],
            (string) $validated['new_quantity'],
            $request->user(),
            [
                'reason' => $validated['reason'],
            ],
        );

        return back()->with('success', 'Stock count corrected.');
    }

    public function receive(InventoryReceiveRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'inventory', 'receive');

        $validated = $request->validated();
        $this->inventoryService->receive(
            (int) $validated['inventory_item_id'],
            (int) $validated['stock_location_id'],
            (string) $validated['quantity'],
            $request->user(),
            (string) $validated['unit_cost'],
            [
                'reference_entity_type' => 'stock_receive',
                'reference_entity_id' => null,
                'note' => $validated['note'] ?? null,
            ],
        );

        return back()->with('success', 'Stock received onto the shelf.');
    }

    /**
     * @return array{
     *     inventory_items: \Illuminate\Support\Collection<int, InventoryItem>,
     *     stock_locations: \Illuminate\Support\Collection<int, StockLocation>,
     *     recipients: \Illuminate\Support\Collection<int, User>,
     *     projects: \Illuminate\Support\Collection<int, Project>
     * }
     */
    private function formOptions(): array
    {
        return [
            'inventory_items' => InventoryItem::query()->orderBy('name')->get(['id', 'code', 'name', 'unit']),
            'stock_locations' => StockLocation::query()->orderBy('name')->get(['id', 'name', 'project_id']),
            'recipients' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }
}
