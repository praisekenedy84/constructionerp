<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveCashRequest;
use App\Http\Requests\CashReceiveRequest;
use App\Http\Requests\CashRequestRequest;
use App\Models\CashAllocation;
use App\Models\Project;
use App\Models\User;
use App\Services\CashAllocationService;
use App\Services\FundRequestExportService;
use App\Services\MoneyAccountService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashController extends Controller
{
    public function __construct(
        private CashAllocationService $cashService,
        private FundRequestExportService $fundRequestExport,
        private MoneyAccountService $moneyAccountService,
    ) {}

    public function request(CashRequestRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'create');

        $validated = $request->validated();

        $allocation = $this->cashService->request(
            (string) $validated['requested_amount'],
            $request->user(),
        );

        $this->notifyManagers($allocation);

        return back()->with('success', "Cash request #{$allocation->id} submitted.");
    }

    public function approve(ApproveCashRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'approve');

        $allocation = CashAllocation::findOrFail($id);

        try {
            $this->cashService->approve($allocation, $request->user(), $request->validated());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Fund request approved — amount transferred to the Finance Wallet.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'reject');

        $allocation = CashAllocation::findOrFail($id);
        $this->cashService->reject($allocation, $request->user(), $request->input('reason'));

        return back()->with('success', 'Cash request rejected.');
    }

    public function receive(CashReceiveRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'receive');

        $allocation = CashAllocation::findOrFail($id);
        $validated = $request->validated();

        try {
            $this->cashService->receive(
                $allocation,
                (string) $validated['received_amount'],
                $request->user(),
                [
                    'method' => $validated['method'] ?? null,
                    'reference_no' => $validated['reference_no'] ?? null,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cash receipt recorded.');
    }

    public function cashFlow(Request $request, int $projectId): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $project = Project::findOrFail($projectId);

        $listing = ListingQuery::for(
            CashAllocation::query()->where('project_id', $project->id),
            $request,
        )
            ->search(['reference_no', 'method'])
            ->dateRange('requested_at')
            ->sort(['requested_at', 'status', 'requested_amount', 'received_amount'], 'requested_at');

        return Inertia::render('Finance/CashFlow', [
            'project' => $project,
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'allocations' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function reconciliation(Request $request, int $projectId): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $project = Project::findOrFail($projectId);

        return Inertia::render('Finance/Reconciliation', [
            'project' => $project,
            'summary' => $this->cashService->reconciliation($project),
        ]);
    }

    public function organizationCash(Request $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        return redirect()->route('finance.finance-transactions');
    }

    public function fundApprovals(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $this->moneyAccountService->ensureFinanceAccount($request->user());

        $paginator = $this->fundRequestExport->list($request);

        $managerAccounts = collect($this->moneyAccountService->managerAccounts())
            ->map(fn ($account) => $this->moneyAccountService->formatAccount($account))
            ->values()
            ->all();

        return Inertia::render('Finance/FundApprovals', [
            'allocations' => $paginator->through(fn (CashAllocation $allocation) => [
                'id' => $allocation->id,
                'project_id' => $allocation->project_id,
                'source_account_id' => $allocation->source_account_id,
                'requested_amount' => (string) $allocation->requested_amount,
                'received_amount' => (string) $allocation->received_amount,
                'utilized_amount' => (string) $allocation->utilized_amount,
                'balance' => (string) $allocation->balance,
                'status' => $allocation->status->value,
                'method' => $allocation->method,
                'reference_no' => $allocation->reference_no,
                'requested_at' => $allocation->requested_at?->toIso8601String(),
                'received_at' => $allocation->received_at?->toIso8601String(),
                'decided_at' => $allocation->decided_at?->toIso8601String(),
                'rejection_reason' => $allocation->rejection_reason,
                'source_account' => $allocation->sourceAccount ? [
                    'id' => $allocation->sourceAccount->id,
                    'name' => $allocation->sourceAccount->name,
                ] : null,
                'project' => $allocation->project ? [
                    'id' => $allocation->project->id,
                    'code' => $allocation->project->code,
                    'name' => $allocation->project->name,
                ] : null,
                'requester' => $allocation->requester ? [
                    'id' => $allocation->requester->id,
                    'name' => $allocation->requester->name,
                ] : null,
                'approver' => $allocation->approver ? [
                    'id' => $allocation->approver->id,
                    'name' => $allocation->approver->name,
                ] : null,
            ]),
            'manager_accounts' => $managerAccounts,
            'finance_balance' => $this->moneyAccountService->financeBalance(),
            'filters' => $request->only(['search', 'from', 'to', 'sort', 'direction', 'status']),
            'summary' => [
                'total' => CashAllocation::count(),
                'pending' => CashAllocation::where('status', 'pending')->count(),
                'approved' => CashAllocation::where('status', 'approved')->count(),
                'received' => CashAllocation::where('status', 'received')->count(),
                'rejected' => CashAllocation::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function exportFundApprovals(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $format = $request->string('format', 'xlsx')->toString();

        return match ($format) {
            'pdf' => $this->fundRequestExport->exportPdf($request),
            'xlsx', 'excel' => $this->fundRequestExport->exportExcel($request),
            default => abort(422, 'Unsupported export format. Use xlsx or pdf.'),
        };
    }

    private function notifyManagers(CashAllocation $allocation): void
    {
        User::role('Manager')->each(function (User $user) use ($allocation) {
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'type' => 'cash_allocation_requested',
                'data' => [
                    'allocation_id' => $allocation->id,
                    'amount' => (string) $allocation->requested_amount,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
