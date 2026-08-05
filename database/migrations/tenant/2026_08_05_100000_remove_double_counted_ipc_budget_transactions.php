<?php

use App\Enums\BudgetTransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * IPC compliance deductions are withheld from the phase disbursement before net_budget
     * is derived, but they were also posted as DIRECT_EXPENSE budget transactions, so every
     * IPC was subtracted from the remaining budget twice. Drop the duplicate charges (and
     * any reversals of them) so historical remaining balances correct themselves.
     */
    public function up(): void
    {
        DB::table('budget_transactions')
            ->where('type', BudgetTransactionType::DirectExpense->value)
            ->where('reference_entity_type', 'expense')
            ->whereIn(
                'reference_entity_id',
                DB::table('expenses')->whereNotNull('valuation_id')->select('id'),
            )
            ->delete();
    }

    public function down(): void
    {
        // The removed rows were duplicates; there is nothing meaningful to restore.
    }
};
