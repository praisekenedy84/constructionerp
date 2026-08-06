<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\RecordPurchaseOrderPaymentRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Models\Equipment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Services\ProcurementService;
use App\Support\ListingQuery;
use Illuminate\Database\Eloquent\Builder;
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

        $query = PurchaseOrder::query()
            ->with(['supplier', 'requisition', 'equipment', 'items'])
            ->withSum('payments', 'amount');

        $this->applyIndexFilters($query, $request);

        $listing = ListingQuery::for(
            $query,
            $request,
        )
            ->search(['purchase_order_no', 'status', 'supplier.name', 'requisition.requisition_no'])
            ->dateRange('purchase_date');

        $summaryQuery = clone $query;
        $orderCount = (clone $summaryQuery)->count();
        $totalAmount = (string) ((clone $summaryQuery)->sum('total_amount') ?? 0);
        $paidAmount = (string) PurchaseOrderPayment::query()
            ->whereIn('purchase_order_id', (clone $summaryQuery)->select('purchase_orders.id'))
            ->sum('amount');

        $listing->sort(
            ['purchase_date', 'created_at', 'status', 'total_amount'],
            'purchase_date',
        );

        return Inertia::render('Procurement/PurchaseOrders', [
            'purchase_orders' => $listing->paginate(25),
            'summary' => [
                'order_count' => $orderCount,
                'total_amount' => bcadd($totalAmount, '0', 2),
                'paid_amount' => bcadd($paidAmount, '0', 2),
                'outstanding_amount' => bcsub($totalAmount, $paidAmount, 2),
            ],
            'filters' => $listing->filters($request->only([
                'status',
                'payment_status',
                'supplier_id',
                'requisition_id',
                'equipment_id',
            ])),
            'filter_options' => [
                'requisitions' => Requisition::query()
                    ->whereHas('purchaseOrders')
                    ->orderByDesc('updated_at')
                    ->get(['id', 'requisition_no']),
                'equipment' => Equipment::query()
                    ->whereIn(
                        'id',
                        PurchaseOrder::query()
                            ->whereNotNull('equipment_id')
                            ->select('equipment_id'),
                    )
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
            'requisitions' => Requisition::query()
                ->whereIn('status', ['approved', 'amended', 'partially_fulfilled'])
                ->with('project')
                ->withSum([
                    'purchaseOrders as purchase_orders_allocated' => fn ($query) => $query
                        ->where('status', '!=', PurchaseOrderStatus::Cancelled->value),
                ], 'total_amount')
                ->withSum([
                    'purchaseOrderPayments as purchase_orders_paid' => fn ($query) => $query
                        ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value),
                ], 'amount')
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (Requisition $requisition) {
                    $approved = bcadd(
                        (string) ($requisition->amended_amount ?? $requisition->original_amount),
                        '0',
                        2,
                    );
                    $purchaseOrdersAllocated = bcadd(
                        (string) ($requisition->purchase_orders_allocated ?? 0),
                        '0',
                        2,
                    );
                    $purchasePayments = bcadd(
                        (string) ($requisition->purchase_orders_paid ?? 0),
                        '0',
                        2,
                    );
                    $fulfilled = bcadd((string) $requisition->fulfilled_amount, '0', 2);
                    $nonPurchaseFulfilled = bccomp($fulfilled, $purchasePayments, 2) === 1
                        ? bcsub($fulfilled, $purchasePayments, 2)
                        : '0.00';
                    $utilized = bcadd($purchaseOrdersAllocated, $nonPurchaseFulfilled, 2);
                    $remaining = bcsub($approved, $utilized, 2);

                    $requisition->setAttribute('allocated_amount', $approved);
                    $requisition->setAttribute('utilized_amount', $utilized);
                    $requisition->setAttribute('available_balance', $remaining);

                    return $requisition;
                })
                ->filter(fn (Requisition $requisition) => bccomp(
                    (string) $requisition->available_balance,
                    '0',
                    2,
                ) === 1)
                ->values(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'equipment' => Equipment::query()
                ->where('status', '!=', 'retired')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'status']),
        ]);
    }

    private function applyIndexFilters(Builder $query, Request $request): void
    {
        foreach (['supplier_id', 'requisition_id', 'equipment_id'] as $foreignKey) {
            if ($request->filled($foreignKey)) {
                $query->where($foreignKey, $request->integer($foreignKey));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $paymentStatus = $request->string('payment_status')->toString();
        $paymentTotal = '(select coalesce(sum(purchase_order_payments.amount), 0) '
            .'from purchase_order_payments '
            .'where purchase_order_payments.purchase_order_id = purchase_orders.id)';

        if ($paymentStatus === 'unpaid') {
            $query->whereRaw("{$paymentTotal} = 0");
        } elseif ($paymentStatus === 'partially_paid') {
            $query
                ->whereRaw("{$paymentTotal} > 0")
                ->whereRaw("{$paymentTotal} < purchase_orders.total_amount");
        } elseif ($paymentStatus === 'paid') {
            $query->whereRaw("{$paymentTotal} >= purchase_orders.total_amount");
        }
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
            $validated,
            $request->user(),
        );

        $po = $result['purchase_order'];
        $message = "Purchase order {$po->purchase_order_no} created.";
        if ($result['variance'] !== null) {
            $message .= ' Note: unit cost differs from BOQ rate.';
        }

        return back()->with('success', $message);
    }

    public function recordPayment(
        RecordPurchaseOrderPaymentRequest $request,
        int $id,
    ): RedirectResponse {
        $this->authorizePermission($request->user(), 'procurement', 'update');

        $purchaseOrder = PurchaseOrder::findOrFail($id);
        $validated = $request->validated();

        $this->procurementService->recordPayment(
            $purchaseOrder,
            (string) $validated['amount'],
            $request->user(),
            $validated,
        );

        return back()->with('success', "Supplier payment recorded for {$purchaseOrder->purchase_order_no}.");
    }
}
