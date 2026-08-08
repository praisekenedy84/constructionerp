<?php

namespace App\Http\Controllers;

use App\Http\Requests\CollectSaleReceivableRequest;
use App\Models\Sale;
use App\Services\MoneyAccountService;
use App\Services\SaleService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $saleService,
        private MoneyAccountService $moneyAccountService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'sales', 'read');

        $this->saleService->ensureAllPhasesHaveSales();

        // Archived projects keep their sale history but drop out of the register.
        $query = Sale::query()
            ->whereHas('project')
            ->with([
                'project:id,code,name,client,contract_amount,net_budget,status',
                'phase:id,project_id,sequence_no,name,status,disbursed_amount,phase_net_budget',
            ]);

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['sale_code', 'project.code', 'project.name', 'project.client', 'phase.name'])
            ->dateRange('created_at')
            ->sort(['sale_code', 'status', 'created_at', 'converted_at']);

        $sales = $listing->paginate(ListingQuery::PER_PAGE);
        $sales->getCollection()->transform(
            fn (Sale $sale) => $this->saleService->formatSale($sale)
        );

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $listing->filters([
                'status' => $request->input('status', 'all'),
            ]),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'sales', 'read');

        $sale = Sale::with([
            'project:id,code,name,client,contract_amount,net_budget,status,location',
            'phase:id,project_id,sequence_no,name,status,disbursed_amount,phase_net_budget',
            'converter:id,name',
            'payments' => fn ($q) => $q->orderByDesc('occurred_at')->orderByDesc('id'),
            'payments.account:id,name,type',
            'payments.recorder:id,name',
        ])->findOrFail($id);

        return Inertia::render('Sales/Show', [
            'sale' => $this->saleService->formatSale($sale),
            'payments' => $sale->payments
                ->map(fn ($payment) => $this->saleService->formatPayment($payment))
                ->values()
                ->all(),
            'manager_accounts' => collect($this->moneyAccountService->managerAccounts())
                ->map(fn ($account) => $this->moneyAccountService->formatAccount($account))
                ->values()
                ->all(),
        ]);
    }

    public function convert(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'sales', 'convert');

        $sale = Sale::findOrFail($id);

        try {
            $this->saleService->convertToReceivable($sale, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        }

        return back()->with('success', 'Profit converted to receivable.');
    }

    public function collect(CollectSaleReceivableRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'sales', 'collect');

        $sale = Sale::findOrFail($id);

        try {
            $this->saleService->collect($sale, $request->user(), $request->validated());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Receivable collection recorded.');
    }
}
