<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Services\PayrollService;
use App\Services\ReportService;
use App\Support\ListingQuery;
use App\Support\OrganizationFundUse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            $this->expenseService->store($request->validated(), $request->user());
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

        $listing = $this->buildListing(ExpenseCategory::Direct, $request);
        $filterOptions = $this->expenseService->filterOptions(ExpenseCategory::Direct);

        return Inertia::render('Finance/Expenses', [
            'expenses' => $listing->paginate(25),
            'summary' => $this->expenseService->summary(ExpenseCategory::Direct, $request),
            'filterOptions' => $filterOptions,
            'projects' => $filterOptions['projects'],
            'filters' => $listing->filters([
                'project_id' => $request->input('project_id'),
                'sub_type' => $request->input('sub_type') ?: $request->input('category'),
                'source' => $request->input('source'),
                'recorded_by' => $request->input('recorded_by'),
            ]),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        return $this->expenseService->exportExcel(ExpenseCategory::Direct, $request);
    }

    public function overhead(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        // Posted runs created before salaries moved to overhead still only have PAYROLL
        // budget txs — migrate them so the ledger stays complete.
        $this->payrollService->backfillLegacyPayrollOverhead($request->user());

        $listing = $this->buildListing(ExpenseCategory::Indirect, $request);
        $filterOptions = $this->expenseService->filterOptions(ExpenseCategory::Indirect);
        $summary = $this->expenseService->summary(ExpenseCategory::Indirect, $request);

        return Inertia::render('Finance/Overhead', [
            'expenses' => $listing->paginate(25),
            'summary' => $summary,
            'total_overhead' => $summary['total_amount'],
            'organization_cash' => $this->reportService->cashPosition(['scope' => 'organization']),
            'purpose_options' => OrganizationFundUse::subtypes(),
            'filterOptions' => $filterOptions,
            'filters' => $listing->filters([
                'sub_type' => $request->input('sub_type'),
                'source' => $request->input('source'),
                'recorded_by' => $request->input('recorded_by'),
            ]),
        ]);
    }

    public function exportOverhead(Request $request): BinaryFileResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        return $this->expenseService->exportExcel(ExpenseCategory::Indirect, $request);
    }

    private function buildListing(ExpenseCategory $category, Request $request): ListingQuery
    {
        $with = [
            'requisition:id,requisition_no,status',
            'recorder:id,name',
            'cashDisbursements.cashAllocation:id,project_id,reference_no',
        ];

        if ($category === ExpenseCategory::Direct) {
            $with[] = 'project:id,code,name';
            $with[] = 'boqItem:id,description,unit';
        }

        $query = $this->expenseService
            ->filteredQuery($category, $request)
            ->with($with);

        $search = [
            'description',
            'sub_type',
            'activity_ref',
            'requisition.requisition_no',
            'recorder.name',
        ];

        if ($category === ExpenseCategory::Direct) {
            $search[] = 'project.name';
            $search[] = 'project.code';
        }

        return ListingQuery::for($query, $request)
            ->search($search)
            ->dateRange('expense_date')
            ->sort(['expense_date', 'amount', 'sub_type', 'created_at'], 'expense_date');
    }
}
