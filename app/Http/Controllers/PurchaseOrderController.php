<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Services\ProcurementService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private ProcurementService $procurementService) {}

    public function index(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Procurement Officer']);

        $listing = ListingQuery::for(
            PurchaseOrder::query()->with(['supplier', 'requisition']),
            $request,
        )
            ->search(['status', 'supplier.name', 'requisition.requisition_no'])
            ->dateRange('created_at')
            ->sort(['created_at', 'status', 'total_amount'], 'created_at');

        return Inertia::render('Procurement/PurchaseOrders', [
            'purchase_orders' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'requisitions' => Requisition::query()
                ->whereIn('status', ['approved', 'amended'])
                ->with('project')
                ->orderByDesc('updated_at')
                ->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Procurement Officer']);

        $po = $this->procurementService->createPOFromRequisition(
            $request->validated(),
            $request->user(),
        );

        return back()->with('success', "Purchase order #{$po->id} created.");
    }
}
