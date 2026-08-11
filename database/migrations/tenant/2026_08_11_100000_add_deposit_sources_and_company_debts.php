<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->string('deposit_source')->nullable()->after('type');
        });

        Schema::create('company_debts', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // loan | customer_advance
            $table->string('creditor_name');
            $table->decimal('original_amount', 15, 2);
            $table->decimal('outstanding_amount', 15, 2);
            $table->string('status'); // open | partially_paid | cleared
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('deposit_transaction_id')->nullable()
                ->constrained('account_transactions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index('occurred_at');
        });

        Schema::create('company_debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_debt_id')->constrained('company_debts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('account_transaction_id')->nullable()
                ->constrained('account_transactions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('method')->nullable();
            $table->string('reference_no')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_debt_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_debt_payments');
        Schema::dropIfExists('company_debts');

        Schema::table('account_transactions', function (Blueprint $table) {
            $table->dropColumn('deposit_source');
        });
    }
};
