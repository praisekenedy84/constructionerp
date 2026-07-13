<?php

namespace App\Services;

use App\Enums\BudgetTransactionType;
use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    /**
     * @param  array{
     *     project_id?: int|null,
     *     boq_item_id?: int|null,
     *     category: ExpenseCategory|string,
     *     sub_type: string,
     *     activity_ref?: string|null,
     *     asset_reg_no?: string|null,
     *     amount: string|float,
     *     description?: string|null,
     *     expense_date: string,
     *     recorded_by: int,
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
                'category' => $category,
                'sub_type' => $data['sub_type'],
                'activity_ref' => $data['activity_ref'] ?? null,
                'asset_reg_no' => $data['asset_reg_no'] ?? null,
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'expense_date' => $data['expense_date'],
                'recorded_by' => $data['recorded_by'],
            ]);

            if ($category === ExpenseCategory::Direct) {
                $this->budgetService->createTransaction((int) $data['project_id'], [
                    'type' => BudgetTransactionType::DirectExpense,
                    'amount' => $amount,
                    'boq_item_id' => $data['boq_item_id'] ?? null,
                    'reference_entity_type' => 'expense',
                    'reference_entity_id' => $expense->id,
                    'created_by' => $data['recorded_by'],
                ]);
            }

            return $expense;
        });
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
}
