<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('sale_code', 40)->unique();
            $table->string('status', 30)->default('open');
            $table->decimal('contract_amount', 15, 2)->nullable();
            $table->decimal('profit_amount', 15, 2)->nullable();
            $table->decimal('collected_amount', 15, 2)->default(0);
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('project_id');
            $table->index('status');
        });

        Schema::create('sale_receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('account_transaction_id')->nullable()->constrained('account_transactions')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method', 30)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['sale_id', 'occurred_at']);
        });

        $projects = DB::table('projects')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'code', 'contract_amount']);
        $now = now();

        foreach ($projects as $project) {
            DB::table('sales')->insert([
                'project_id' => $project->id,
                'sale_code' => $this->saleCodeFor((string) $project->code, (int) $project->id),
                'status' => 'open',
                'contract_amount' => $project->contract_amount,
                'profit_amount' => null,
                'collected_amount' => '0.00',
                'converted_at' => null,
                'converted_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_receivable_payments');
        Schema::dropIfExists('sales');
    }

    private function saleCodeFor(string $projectCode, int $projectId): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $projectCode) ?: 'PRJ');

        return 'SALE-'.$normalized.'-'.$projectId;
    }
};
