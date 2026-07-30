<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Project;
use App\Services\ExpenseService;
use App\Services\PayrollService;
use App\Services\ReportService;
use App\Support\ListingQuery;
use App\Support\OrganizationFundUse;
use App\Enums\ExpenseCategory;
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
        private ReportService $reportService,
    ) {}

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'create');

        try {
            $expense = $this->expenseService->store($request->validated(), $request->user());
        } catch (\App\Exceptions\InsufficientCashException|\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Expense recorded and cash on hand reduced.');
    }

    public function update(StoreExpenseRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'update');
        $expense = Expense::findOrFail($id);

        try {
            $this->expenseService->update($expense, $request->validated(), $request->user());
        } catch (\App\Exceptions\InsufficientCashException|\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Expense updated and cash on hand adjusted.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'update');
        $expense = Expense::findOrFail($id);
        $this->expenseService->destroy($expense, $request->user());

        return back()->with('success', 'Expense deleted and cash returned to cash on hand.');
    }

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $query = Expense::query()
            ->with(['project', 'cashDisbursements'])
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

        $query = Expense::query()
            ->with('cashDisbursements')
            ->where('category', ExpenseCategory::Indirect);

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
            'organization_cash' => $this->reportService->cashPosition(['scope' => 'organization']),
            'purpose_options' => OrganizationFundUse::subtypes(),
            'filters' => $listing->filters(['sub_type' => $request->input('sub_type')]),
        ]);
    }
}
