<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientCashException;
use App\Exports\ExpenseExport;
use App\Models\CashDisbursement;
use App\Models\BudgetTransaction;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Support\ListingQuery;
use App\Support\OrganizationFundUse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpenseService
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    /**
     * @param  array{
     *     project_id?: int|null,
     *     boq_item_id?: int|null,
     *     requisition_id?: int|null,
     *     valuation_id?: int|null,
     *     category: ExpenseCategory|string,
     *     sub_type: string,
     *     activity_ref?: string|null,
     *     asset_reg_no?: string|null,
     *     amount: string|float,
     *     description?: string|null,
     *     expense_date: string,
     *     recorded_by: int,
     *     cash_allocation_id?: int|null,
     *     method?: string|null,
     *     payee?: string|null,
     *     reference_no?: string|null,
     * }  $data
     */
    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            $category = $data['category'] instanceof ExpenseCategory
                ? $data['category']
                : ExpenseCategory::from($data['category']);

            $amount = bcadd((string) $data['amount'], '0', 2);

            if ($category === ExpenseCategory::Direct && empty($data['project_id'])) {
                throw new \InvalidArgumentException('Direct expenses require a project_id.');
            }

            $expense = Expense::create([
                'project_id' => $category === ExpenseCategory::Direct ? $data['project_id'] : null,
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'requisition_id' => $data['requisition_id'] ?? null,
                'valuation_id' => $data['valuation_id'] ?? null,
                'category' => $category,
                'sub_type' => $data['sub_type'] ?? OrganizationFundUse::GENERAL,
                'activity_ref' => $data['activity_ref'] ?? null,
                'asset_reg_no' => $data['asset_reg_no'] ?? null,
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'expense_date' => $data['expense_date'],
                'recorded_by' => $data['recorded_by'],
            ]);

            // When method is present, cash is taken from the shared finance wallet.
            // Legacy payroll / IPC compliance omit method and stay accounting-only.
            if (array_key_exists('method', $data)) {
                $this->disburseFromFinanceWallet(
                    $expense,
                    $amount,
                    $data,
                    (int) $data['recorded_by'],
                );
            }

            if ($category === ExpenseCategory::Direct && $expense->project_id) {
                $this->postDirectExpenseBudget($expense, (int) $data['recorded_by']);
            }

            return $expense;
        });
    }

    /**
     * Spend from the shared Finance Wallet (project and company expenses share one balance).
     * Project costs hit the budget ledger via DIRECT_EXPENSE; cash availability is wallet-level.
     *
     * @param  array<string, mixed>  $data
     */
    private function disburseFromFinanceWallet(
        Expense $expense,
        string $amount,
        array $data,
        int $actorId,
    ): void {
        $actor = User::findOrFail($actorId);
        $finance = $this->moneyAccountService->ensureFinanceAccount($actor);
        $available = bcadd((string) $finance->balance, '0', 2);

        if (bccomp($available, $amount, 2) < 0) {
            throw new InsufficientCashException(
                $amount,
                $available,
                'Reduce the amount, or request more funds into the Finance Wallet.',
                'Finance Wallet',
            );
        }

        $tx = $this->moneyAccountService->disburseFromFinance($amount, $actor, [
            'description' => $expense->description
                ? "Expense: {$expense->description}"
                : "Expense #{$expense->id}",
            'reference_no' => $data['reference_no'] ?? null,
            'method' => $data['method'] ?? PaymentMethod::Cash->value,
            'reference_entity_type' => 'expense',
            'reference_entity_id' => $expense->id,
            'occurred_at' => $data['expense_date'],
        ]);

        CashDisbursement::create([
            'expense_id' => $expense->id,
            'cash_allocation_id' => null,
            'money_account_id' => $finance->id,
            'account_transaction_id' => $tx->id,
            'amount' => $amount,
            'method' => $data['method'] ?? PaymentMethod::Cash->value,
            'payee' => $data['payee'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'disbursed_by' => $actorId,
            'disbursed_at' => $data['expense_date'],
            'created_at' => now(),
        ]);
    }

    private function postDirectExpenseBudget(Expense $expense, int $actorId): void
    {
        if (! $expense->project_id) {
            return;
        }

        $this->budgetService->createTransaction((int) $expense->project_id, [
            'type' => BudgetTransactionType::DirectExpense,
            'amount' => bcadd((string) $expense->amount, '0', 2),
            'boq_item_id' => $expense->boq_item_id,
            'reference_entity_type' => 'expense',
            'reference_entity_id' => $expense->id,
            'reason' => 'Direct expense charged to project budget',
            'created_by' => $actorId,
        ]);
    }

    public function update(Expense $expense, array $data, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $data, $user) {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            $this->assertEditable($expense);

            $oldDisbursements = CashDisbursement::query()
                ->where('expense_id', $expense->id)
                ->lockForUpdate()
                ->get();

            foreach ($oldDisbursements as $disbursement) {
                if ($disbursement->account_transaction_id) {
                    $tx = \App\Models\AccountTransaction::find($disbursement->account_transaction_id);
                    if ($tx) {
                        $this->moneyAccountService->reverseFinanceDisbursement(
                            $tx,
                            $user,
                            "Expense #{$expense->id} edited — reversing prior disbursement",
                        );
                    }
                }
                $disbursement->delete();
            }

            $this->reverseDirectExpenseBudget($expense, $user);

            $category = $data['category'] instanceof ExpenseCategory
                ? $data['category']
                : ExpenseCategory::from($data['category']);
            $amount = bcadd((string) $data['amount'], '0', 2);
            $projectId = $category === ExpenseCategory::Direct
                ? (int) $data['project_id']
                : null;
            $expense->update([
                'project_id' => $projectId,
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'category' => $category,
                'sub_type' => $data['sub_type'] ?? $expense->sub_type ?? OrganizationFundUse::GENERAL,
                'activity_ref' => $data['activity_ref'] ?? null,
                'asset_reg_no' => $data['asset_reg_no'] ?? null,
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'expense_date' => $data['expense_date'],
            ]);

            $this->disburseFromFinanceWallet($expense, $amount, $data, $user->id);

            if ($category === ExpenseCategory::Direct && $expense->project_id) {
                $this->postDirectExpenseBudget($expense->refresh(), $user->id);
            }

            return $expense->refresh();
        });
    }

    public function destroy(Expense $expense, User $user): void
    {
        DB::transaction(function () use ($expense, $user) {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            $this->assertEditable($expense);

            $disbursements = CashDisbursement::query()
                ->where('expense_id', $expense->id)
                ->lockForUpdate()
                ->get();

            foreach ($disbursements as $disbursement) {
                if ($disbursement->account_transaction_id) {
                    $tx = \App\Models\AccountTransaction::find($disbursement->account_transaction_id);
                    if ($tx) {
                        $this->moneyAccountService->reverseFinanceDisbursement(
                            $tx,
                            $user,
                            "Expense #{$expense->id} deleted — reversing disbursement",
                        );
                    }
                }
                $disbursement->delete();
            }

            $this->reverseDirectExpenseBudget($expense, $user);
            $expense->delete();
        });
    }

    private function assertEditable(Expense $expense): void
    {
        if ($expense->valuation_id) {
            throw new \InvalidArgumentException(
                'IPC compliance expenses are managed from project valuations and cannot be edited here.',
            );
        }
    }

    private function reverseDirectExpenseBudget(Expense $expense, User $user): void
    {
        $netPosted = (string) BudgetTransaction::query()
            ->where('type', BudgetTransactionType::DirectExpense)
            ->where('reference_entity_type', 'expense')
            ->where('reference_entity_id', $expense->id)
            ->sum('amount');

        if (bccomp($netPosted, '0', 2) === 0 || ! $expense->project_id) {
            return;
        }

        $this->budgetService->createTransaction((int) $expense->project_id, [
            'type' => BudgetTransactionType::DirectExpense,
            'amount' => bcmul($netPosted, '-1', 2),
            'boq_item_id' => $expense->boq_item_id,
            'reference_entity_type' => 'expense',
            'reference_entity_id' => $expense->id,
            'reason' => 'Expense edited or deleted; reversing prior budget charge.',
            'created_by' => $user->id,
        ]);
    }

    public function store(array $data, User $user): Expense
    {
        return $this->create([
            ...$data,
            'recorded_by' => $user->id,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Expense>
     */
    public function list(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return Expense::query()
            ->with('project')
            ->where('category', ExpenseCategory::Direct)
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->orderByDesc('expense_date')
            ->limit(200)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Expense>
     */
    public function overhead(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return Expense::query()
            ->where('category', ExpenseCategory::Indirect)
            ->when($filters['sub_type'] ?? null, fn ($q, $type) => $q->where('sub_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->orderByDesc('expense_date')
            ->limit(200)
            ->get();
    }

    public function overheadTotal(array $filters = []): string
    {
        $total = '0';

        foreach ($this->overhead($filters) as $expense) {
            $total = bcadd($total, (string) $expense->amount, 2);
        }

        return $total;
    }

    /**
     * @return Builder<Expense>
     */
    public function filteredQuery(ExpenseCategory $category, Request $request): Builder
    {
        $query = Expense::query()->where('category', $category);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        $subType = $request->input('sub_type') ?: $request->input('category');
        if (filled($subType)) {
            $query->where('sub_type', $subType);
        }

        if ($request->filled('recorded_by')) {
            $query->where('recorded_by', $request->integer('recorded_by'));
        }

        $source = (string) $request->input('source', '');
        if ($source === 'requisition') {
            $query->whereNotNull('requisition_id');
        } elseif ($source === 'ipc') {
            $query->whereNotNull('valuation_id');
        } elseif ($source === 'manual') {
            $query->whereNull('requisition_id')->whereNull('valuation_id');
        }

        return $query;
    }

    /**
     * @return array{
     *     total_amount: string,
     *     from_requisitions: string,
     *     from_ipcs: string,
     *     manual_amount: string,
     *     cash_disbursed: string,
     *     expense_count: int,
     *     requisition_count: int,
     *     ipc_count: int,
     *     manual_count: int
     * }
     */
    public function summary(ExpenseCategory $category, Request $request): array
    {
        $query = $this->filteredQuery($category, $request);
        ListingQuery::for($query, $request)
            ->search($this->searchColumns($category))
            ->dateRange('expense_date');

        $aggregates = (clone $query)
            ->toBase()
            ->reorder()
            ->selectRaw('count(*) as expense_count')
            ->selectRaw('coalesce(sum(amount), 0) as total_amount')
            ->selectRaw('coalesce(sum(case when requisition_id is not null then amount else 0 end), 0) as from_requisitions')
            ->selectRaw('coalesce(sum(case when valuation_id is not null then amount else 0 end), 0) as from_ipcs')
            ->selectRaw('coalesce(sum(case when requisition_id is null and valuation_id is null then amount else 0 end), 0) as manual_amount')
            ->selectRaw('coalesce(sum(case when requisition_id is not null then 1 else 0 end), 0) as requisition_count')
            ->selectRaw('coalesce(sum(case when valuation_id is not null then 1 else 0 end), 0) as ipc_count')
            ->selectRaw('coalesce(sum(case when requisition_id is null and valuation_id is null then 1 else 0 end), 0) as manual_count')
            ->first();

        $cashDisbursed = (string) CashDisbursement::query()
            ->whereIn('expense_id', (clone $query)->select('expenses.id'))
            ->sum('amount');

        return [
            'total_amount' => bcadd((string) ($aggregates->total_amount ?? '0'), '0', 2),
            'from_requisitions' => bcadd((string) ($aggregates->from_requisitions ?? '0'), '0', 2),
            'from_ipcs' => bcadd((string) ($aggregates->from_ipcs ?? '0'), '0', 2),
            'manual_amount' => bcadd((string) ($aggregates->manual_amount ?? '0'), '0', 2),
            'cash_disbursed' => bcadd($cashDisbursed, '0', 2),
            'expense_count' => (int) ($aggregates->expense_count ?? 0),
            'requisition_count' => (int) ($aggregates->requisition_count ?? 0),
            'ipc_count' => (int) ($aggregates->ipc_count ?? 0),
            'manual_count' => (int) ($aggregates->manual_count ?? 0),
        ];
    }

    /**
     * @return array{
     *     projects: list<array{id: int, code: string, name: string}>,
     *     sub_types: list<string>,
     *     recorders: list<array{id: int, name: string}>
     * }
     */
    public function filterOptions(ExpenseCategory $category): array
    {
        $subTypes = Expense::query()
            ->where('category', $category)
            ->whereNotNull('sub_type')
            ->where('sub_type', '!=', '')
            ->distinct()
            ->orderBy('sub_type')
            ->pluck('sub_type')
            ->all();

        if ($category === ExpenseCategory::Indirect) {
            $subTypes = collect([...OrganizationFundUse::subtypes(), ...$subTypes])
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        return [
            'projects' => $category === ExpenseCategory::Direct
                ? Project::query()->orderBy('name')->get(['id', 'code', 'name'])->all()
                : [],
            'sub_types' => array_values($subTypes),
            'recorders' => User::query()
                ->whereIn('id', Expense::query()->where('category', $category)->select('recorded_by'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
        ];
    }

    public function exportExcel(ExpenseCategory $category, Request $request): BinaryFileResponse
    {
        $query = $this->filteredQuery($category, $request)
            ->with([
                'project:id,code,name',
                'boqItem:id,description',
                'requisition:id,requisition_no',
                'valuation:id,certificate_no,project_id',
                'recorder:id,name',
                'cashDisbursements',
            ]);

        ListingQuery::for($query, $request)
            ->search($this->searchColumns($category))
            ->dateRange('expense_date')
            ->sort(['expense_date', 'amount', 'sub_type', 'created_at'], 'expense_date');

        $rows = $query->get()->map(function (Expense $expense) {
            $disbursement = $expense->cashDisbursements->first();
            $source = 'Manual';
            if ($expense->valuation_id) {
                $source = 'IPC';
            } elseif ($expense->requisition_id) {
                $source = 'Requisition';
            }

            return [
                optional($expense->expense_date)?->toDateString(),
                $expense->category instanceof ExpenseCategory
                    ? $expense->category->value
                    : (string) $expense->category,
                $expense->project?->code,
                $expense->project?->name,
                $expense->sub_type,
                $expense->description,
                $source,
                $expense->requisition?->requisition_no
                    ?? ($expense->valuation ? 'IPC-'.$expense->valuation->certificate_no : null),
                $disbursement?->method,
                $disbursement?->payee ?? $disbursement?->account_name,
                $disbursement?->reference_no,
                (string) $expense->amount,
                $expense->recorder?->name,
                $expense->activity_ref,
                $expense->boqItem?->description,
            ];
        });

        $label = $category === ExpenseCategory::Indirect ? 'overhead' : 'direct-expenses';
        $title = $category === ExpenseCategory::Indirect ? 'Overhead' : 'Direct Expenses';

        return Excel::download(
            new ExpenseExport($rows, $title),
            $label.'-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** @return list<string> */
    private function searchColumns(ExpenseCategory $category): array
    {
        $columns = [
            'description',
            'sub_type',
            'activity_ref',
            'requisition.requisition_no',
            'recorder.name',
        ];

        if ($category === ExpenseCategory::Direct) {
            $columns[] = 'project.name';
            $columns[] = 'project.code';
        }

        return $columns;
    }
}
