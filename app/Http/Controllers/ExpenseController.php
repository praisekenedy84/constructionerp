<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Project;
use App\Services\ExpenseService;
use App\Services\PayrollService;
use App\Support\ListingQuery;
use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Models\CashAllocation;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseService $expenseService,
        private PayrollService $payrollService,
    ) {}

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'create');

        try {
            $expense = $this->expenseService->store($request->validated(), $request->user());
        } catch (\App\Exceptions\InsufficientCashException|\InvalidArgumentException $e) {
            return back()->withErrors(['cash_allocation_id' => $e->getMessage()]);
        }

        return back()->with('success', $expense->category === ExpenseCategory::Direct
            ? 'Expense recorded and cash on hand reduced.'
            : 'Expense recorded.');
    }

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $query = Expense::query()
            ->with(['project', 'cashDisbursement.cashAllocation:id,project_id,reference_no'])
            ->where('category', ExpenseCategory::Direct);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('category')) {
            $query->where('sub_type', $request->string('category'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['description', 'sub_type', 'activity_ref', 'project.name'])
            ->dateRange('expense_date')
            ->sort(['expense_date', 'amount', 'sub_type', 'created_at'], 'expense_date');

        return Inertia::render('Finance/Expenses', [
            'expenses' => $listing->paginate(25),
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'cash_floats' => $this->spendableFloats(),
            'filters' => $listing->filters([
                'project_id' => $request->input('project_id'),
                'category' => $request->input('category'),
            ]),
        ]);
    }

    public function overhead(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        // Posted runs created before salaries moved to overhead still only have PAYROLL
        // budget txs — migrate them so the ledger stays complete.
        $this->payrollService->backfillLegacyPayrollOverhead($request->user());

        $query = Expense::query()->where('category', ExpenseCategory::Indirect);

        if ($request->filled('sub_type')) {
            $query->where('sub_type', $request->string('sub_type'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['description', 'sub_type', 'activity_ref'])
            ->dateRange('expense_date')
            ->sort(['expense_date', 'amount', 'sub_type', 'created_at'], 'expense_date');

        return Inertia::render('Finance/Overhead', [
            'expenses' => $listing->paginate(25),
            'total_overhead' => $this->expenseService->overheadTotal($request->all()),
            'filters' => $listing->filters(['sub_type' => $request->input('sub_type')]),
        ]);
    }

    /**
     * Received floats with remaining balance that a direct expense can be paid from.
     *
     * @return list<array{
     *     id: int,
     *     project_id: int|null,
     *     received_amount: string,
     *     utilized_amount: string,
     *     balance: string,
     *     reference_no: string|null,
     *     received_at: string|null,
     *     project: array{id: int, code: string, name: string}|null,
     * }>
     */
    private function spendableFloats(): array
    {
        return CashAllocation::query()
            ->with('project:id,code,name')
            ->where('status', CashAllocationStatus::Received)
            ->orderByDesc('received_at')
            ->get()
            ->filter(fn (CashAllocation $allocation) => bccomp($allocation->balance, '0', 2) === 1)
            ->map(fn (CashAllocation $allocation) => [
                'id' => $allocation->id,
                'project_id' => $allocation->project_id,
                'received_amount' => (string) $allocation->received_amount,
                'utilized_amount' => (string) $allocation->utilized_amount,
                'balance' => $allocation->balance,
                'reference_no' => $allocation->reference_no,
                'received_at' => $allocation->received_at?->toDateString(),
                'project' => $allocation->project
                    ? [
                        'id' => $allocation->project->id,
                        'code' => $allocation->project->code,
                        'name' => $allocation->project->name,
                    ]
                    : null,
            ])
            ->values()
            ->all();
    }
}
