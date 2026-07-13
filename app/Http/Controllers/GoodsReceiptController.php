<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\ProcurementService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function __construct(private ProcurementService $procurementService) {}

    public function index(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Storekeeper', 'Procurement Officer']);

        $listing = ListingQuery::for(
            GoodsReceipt::query()->with(['purchaseOrder.supplier']),
            $request,
        )
            ->search(['purchaseOrder.supplier.name'])
            ->dateRange('received_at')
            ->sort(['received_at', 'created_at'], 'received_at');

        return Inertia::render('Procurement/GoodsReceipts', [
            'goods_receipts' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'purchase_orders' => PurchaseOrder::query()
                ->with('supplier')
                ->whereIn('status', ['sent', 'confirmed', 'partially_received'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Storekeeper', 'Procurement Officer']);

        $grn = $this->procurementService->recordGoodsReceipt(
            $request->validated(),
            $request->user(),
        );

        return back()->with('success', "Goods receipt #{$grn->id} recorded.");
    }
}
