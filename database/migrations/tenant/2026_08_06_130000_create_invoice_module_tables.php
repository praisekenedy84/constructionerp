<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_information')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('client')->constrained()->nullOnDelete();
        });

        DB::table('projects')
            ->select('client')
            ->whereNotNull('client')
            ->where('client', '!=', '')
            ->distinct()
            ->orderBy('client')
            ->each(function (object $project): void {
                $customerId = DB::table('customers')->insertGetId([
                    'name' => $project->client,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('projects')
                    ->where('client', $project->client)
                    ->update(['customer_id' => $customerId]);
            });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('phase_id')->constrained('project_phases')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->text('description')->nullable();
            $table->decimal('amount_before_tax', 15, 2);
            $table->string('tax_type')->nullable();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->string('deduction_type')->nullable();
            $table->decimal('deduction_rate', 7, 4)->default(0);
            $table->decimal('deduction_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('status')->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['project_id', 'phase_id']);
            $table->index(['invoice_date', 'due_date']);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number');
            $table->date('payment_date');
            $table->decimal('amount_paid', 15, 2);
            $table->string('payment_method');
            $table->string('receipt_file')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['invoice_id', 'receipt_number']);
        });

        Schema::create('invoice_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('signature_type');
            $table->string('signature_file');
            $table->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('signed_date');
            $table->timestamps();

            $table->unique(['invoice_id', 'signature_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_signatures');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
