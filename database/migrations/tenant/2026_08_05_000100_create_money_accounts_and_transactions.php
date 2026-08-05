<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // manager | finance
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('description')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('method')->nullable();
            $table->foreignId('related_account_id')->nullable()->constrained('money_accounts')->nullOnDelete();
            $table->string('reference_entity_type')->nullable();
            $table->unsignedBigInteger('reference_entity_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['money_account_id', 'occurred_at']);
            $table->index(['reference_entity_type', 'reference_entity_id']);
        });

        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->foreignId('source_account_id')->nullable()->after('project_id')
                ->constrained('money_accounts')->nullOnDelete();
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->foreignId('money_account_id')->nullable()->after('cash_allocation_id')
                ->constrained('money_accounts')->nullOnDelete();
            $table->foreignId('account_transaction_id')->nullable()->after('money_account_id')
                ->constrained('account_transactions')->nullOnDelete();
        });

        // Allow disbursements that draw from the shared finance wallet without a float row.
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropForeign(['cash_allocation_id']);
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_allocation_id')->nullable()->change();
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->foreign('cash_allocation_id')
                ->references('id')
                ->on('cash_allocations')
                ->restrictOnDelete();
        });

        $this->migrateLegacyCashFloats();
    }

    public function down(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_transaction_id');
            $table->dropConstrainedForeignId('money_account_id');
        });

        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_account_id');
        });

        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('money_accounts');
    }

    private function migrateLegacyCashFloats(): void
    {
        $now = now();

        $financeAccountId = DB::table('money_accounts')->insertGetId([
            'name' => 'Finance Wallet',
            'type' => 'finance',
            'balance' => '0.00',
            'is_active' => true,
            'notes' => 'Shared operating wallet for project and company spending.',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $managerAccountId = DB::table('money_accounts')->insertGetId([
            'name' => 'Legacy Source',
            'type' => 'manager',
            'balance' => '0.00',
            'is_active' => true,
            'notes' => 'Migrated from project/administrative cash floats.',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $received = DB::table('cash_allocations')
            ->where('status', 'received')
            ->get(['id', 'project_id', 'received_amount', 'utilized_amount', 'received_at', 'approved_by']);

        $totalReceived = '0.00';
        $totalUtilized = '0.00';
        $cashOnHand = '0.00';

        foreach ($received as $row) {
            $recv = bcadd((string) $row->received_amount, '0', 2);
            $util = bcadd((string) $row->utilized_amount, '0', 2);
            $bal = bcsub($recv, $util, 2);

            $totalReceived = bcadd($totalReceived, $recv, 2);
            $totalUtilized = bcadd($totalUtilized, $util, 2);
            if (bccomp($bal, '0', 2) === 1) {
                $cashOnHand = bcadd($cashOnHand, $bal, 2);
            }

            // Unspent project float was already charged to budget at fund approval.
            // Reverse that unspent portion so future expenses hit the budget once.
            if ($row->project_id && bccomp($bal, '0', 2) === 1) {
                $hasBudget = DB::table('budget_transactions')
                    ->where('reference_entity_type', 'cash_allocation')
                    ->where('reference_entity_id', $row->id)
                    ->exists();

                $createdBy = $row->approved_by
                    ?? DB::table('users')->orderBy('id')->value('id');

                if ($hasBudget && $createdBy) {
                    DB::table('budget_transactions')->insert([
                        'project_id' => $row->project_id,
                        'type' => 'CASH_ALLOCATION',
                        'amount' => bcmul($bal, '-1', 2),
                        'boq_item_id' => null,
                        'reference_entity_type' => 'cash_allocation',
                        'reference_entity_id' => $row->id,
                        'reason' => 'Migration: reverse unspent float so expenses post against net budget',
                        'created_by' => $createdBy,
                        'created_at' => $now,
                    ]);
                }
            }

            DB::table('cash_allocations')
                ->where('id', $row->id)
                ->update(['source_account_id' => $managerAccountId]);
        }

        if (bccomp($totalReceived, '0', 2) === 1) {
            DB::table('account_transactions')->insert([
                'money_account_id' => $managerAccountId,
                'type' => 'opening_balance',
                'amount' => $totalReceived,
                'balance_after' => $totalReceived,
                'description' => 'Opening balance from migrated fund receipts',
                'reference_no' => null,
                'method' => null,
                'related_account_id' => null,
                'reference_entity_type' => null,
                'reference_entity_id' => null,
                'recorded_by' => null,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('account_transactions')->insert([
                'money_account_id' => $managerAccountId,
                'type' => 'transfer_out',
                'amount' => $totalReceived,
                'balance_after' => '0.00',
                'description' => 'Migrated transfers to Finance Wallet',
                'reference_no' => null,
                'method' => null,
                'related_account_id' => $financeAccountId,
                'reference_entity_type' => null,
                'reference_entity_id' => null,
                'recorded_by' => null,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('account_transactions')->insert([
                'money_account_id' => $financeAccountId,
                'type' => 'transfer_in',
                'amount' => $totalReceived,
                'balance_after' => $totalReceived,
                'description' => 'Migrated receipts from legacy cash floats',
                'reference_no' => null,
                'method' => null,
                'related_account_id' => $managerAccountId,
                'reference_entity_type' => null,
                'reference_entity_id' => null,
                'recorded_by' => null,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (bccomp($totalUtilized, '0', 2) === 1) {
                $financeBalance = bcsub($totalReceived, $totalUtilized, 2);
                DB::table('account_transactions')->insert([
                    'money_account_id' => $financeAccountId,
                    'type' => 'disbursement',
                    'amount' => $totalUtilized,
                    'balance_after' => $financeBalance,
                    'description' => 'Migrated historical disbursements',
                    'reference_no' => null,
                    'method' => null,
                    'related_account_id' => null,
                    'reference_entity_type' => null,
                    'reference_entity_id' => null,
                    'recorded_by' => null,
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('money_accounts')->where('id', $managerAccountId)->update([
            'balance' => '0.00',
            'updated_at' => $now,
        ]);

        DB::table('money_accounts')->where('id', $financeAccountId)->update([
            'balance' => $cashOnHand,
            'updated_at' => $now,
        ]);

        // Point existing disbursements at the finance wallet for reporting continuity.
        if (Schema::hasColumn('cash_disbursements', 'money_account_id')) {
            DB::table('cash_disbursements')->update(['money_account_id' => $financeAccountId]);
        }
    }
};
