<?php

namespace App\Http\Controllers;

use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Enums\FulfillmentType;
use App\Enums\ProjectStatus;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionStatus;
use App\Models\CashAllocation;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Requisition;
use App\Services\CashAllocationService;
use App\Services\ExpenseService;
use App\Services\MoneyAccountService;
use App\Services\ReportService;
use App\Support\ListingQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(
        private CashAllocationService $cashService,
        private ReportService $reportService,
        private ExpenseService $expenseService,
        private MoneyAccountService $moneyAccountService,
    ) {}

    public function overview(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $this->moneyAccountService->ensureFinanceAccount($request->user());
        $projectCash = $this->reportService->cashPosition([], false);
        $orgOverview = $this->cashService->organizationOverview($projectCash);
        $dashboard = $this->reportService->dashboardOverview();
        $stats = $dashboard['stats'];
        $charts = $dashboard['charts'];

        $managerBalance = $this->moneyAccountService->managerBalance();

        $fundCounts = CashAllocation::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $fundPipeline = [
            'pending' => (int) $fundCounts->get(CashAllocationStatus::Pending->value, 0),
            'approved' => (int) $fundCounts->get(CashAllocationStatus::Approved->value, 0),
            'received' => (int) $fundCounts->get(CashAllocationStatus::Received->value, 0),
            'rejected' => (int) $fundCounts->get(CashAllocationStatus::Rejected->value, 0),
        ];

        $pendingFundAmount = bcadd(
            (string) CashAllocation::query()
                ->where('status', CashAllocationStatus::Pending)
                ->sum('requested_amount'),
            '0',
            2,
        );

        $directExpensesTotal = bcadd(
            (string) Expense::query()
                ->where('category', ExpenseCategory::Direct)
                ->sum('amount'),
            '0',
            2,
        );

        $overheadTotal = $this->expenseService->overheadTotal();

        $awaitingFulfillmentQuery = Requisition::query()
            ->whereIn('status', [
                RequisitionStatus::Approved,
                RequisitionStatus::Amended,
            ])
            ->where(function ($query) {
                $query->where('addressed_to', RequisitionAddressedTo::Finance->value)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('addressed_to')
                            ->whereIn('fulfillment_type', [
                                FulfillmentType::CashDisbursement->value,
                                FulfillmentType::DirectSupplierPayment->value,
                            ]);
                    });
            });

        $awaitingFulfillmentCount = (clone $awaitingFulfillmentQuery)->count();

        $awaitingFulfillment = (clone $awaitingFulfillmentQuery)
            ->with(['project:id,code,name', 'requestor:id,name'])
            ->orderByDesc('updated_at')
            ->paginate(ListingQuery::resolvePerPage($request), ['*'], 'fulfill_page')
            ->withQueryString()
            ->through(fn (Requisition $req) => [
                'id' => $req->id,
                'requisition_no' => $req->requisition_no,
                'status' => $req->status?->value ?? (string) $req->status,
                'fulfillment_type' => $req->fulfillment_type?->value ?? (string) $req->fulfillment_type,
                'amount' => (string) ($req->amended_amount ?? $req->original_amount),
                'updated_at' => $req->updated_at?->toIso8601String(),
                'project' => $req->project ? [
                    'id' => $req->project->id,
                    'code' => $req->project->code,
                    'name' => $req->project->name,
                ] : null,
                'requestor' => $req->requestor ? [
                    'id' => $req->requestor->id,
                    'name' => $req->requestor->name,
                ] : null,
            ]);

        $pendingFunds = CashAllocation::query()
            ->where('status', CashAllocationStatus::Pending)
            ->with(['project:id,code,name', 'requester:id,name'])
            ->orderByDesc('requested_at')
            ->paginate(ListingQuery::resolvePerPage($request), ['*'], 'funds_page')
            ->withQueryString()
            ->through(fn (CashAllocation $allocation) => [
                'id' => $allocation->id,
                'requested_amount' => (string) $allocation->requested_amount,
                'requested_at' => $allocation->requested_at?->toIso8601String(),
                'scope' => 'finance_wallet',
                'project' => $allocation->project ? [
                    'id' => $allocation->project->id,
                    'code' => $allocation->project->code,
                    'name' => $allocation->project->name,
                ] : null,
                'requester' => $allocation->requester ? [
                    'id' => $allocation->requester->id,
                    'name' => $allocation->requester->name,
                ] : null,
            ]);

        $activeProjects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->orderBy('name')
            ->paginate(ListingQuery::resolvePerPage($request), ['*'], 'projects_page')
            ->withQueryString();

        return Inertia::render('Finance/Overview', [
            'summary' => [
                'project_cash_on_hand' => $projectCash['cash_on_hand'],
                'organization_cash_on_hand' => $managerBalance,
                'committed' => $projectCash['committed'],
                'outstanding' => $projectCash['outstanding'],
                'disbursed' => $projectCash['disbursed'],
                'budget_utilization' => $stats['budget_utilization'],
                'pending_fund_count' => $fundPipeline['pending'],
                'pending_fund_amount' => $pendingFundAmount,
                'awaiting_fulfillment_count' => $awaitingFulfillmentCount,
                'direct_expenses_total' => $directExpensesTotal,
                'overhead_total' => $overheadTotal,
            ],
            'fund_pipeline' => $fundPipeline,
            'org_use_breakdown' => $orgOverview['use_breakdown'],
            'project_budget' => $charts['project_budget'],
            'pending_funds' => $pendingFunds,
            'awaiting_fulfillment' => $awaitingFulfillment,
            'active_projects' => $activeProjects,
        ]);
    }

    public function dashboard(Request $request, int $projectId): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $project = Project::findOrFail($projectId);

        $listing = ListingQuery::for(
            CashAllocation::query()->where('project_id', $project->id),
            $request,
        )->sort(['requested_at', 'status', 'requested_amount', 'received_amount'], 'requested_at');

        return Inertia::render('Finance/Index', [
            'project' => $project,
            'reconciliation' => $this->cashService->reconciliation($project),
            'recent_allocations' => $listing->paginate(),
        ]);
    }
}
