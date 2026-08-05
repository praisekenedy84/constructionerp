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
        $this->authorizePermission($request->user(), 'procurement', 'read');

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
        $this->authorizePermission($request->user(), 'procurement', 'create');

        $validated = $request->validated();
        $requisition = Requisition::findOrFail((int) $validated['requisition_id']);
        $supplier = Supplier::findOrFail((int) $validated['supplier_id']);

        $result = $this->procurementService->createPOFromRequisition(
            $requisition,
            $supplier,
            [
                'quantity' => $validated['quantity'],
                'unit_cost' => $validated['unit_cost'],
            ],
        );

        $po = $result['purchase_order'];
        $message = "Purchase order #{$po->id} created.";
        if ($result['variance'] !== null) {
            $message .= ' Note: unit cost differs from BOQ rate.';
        }

        return back()->with('success', $message);
    }
}
