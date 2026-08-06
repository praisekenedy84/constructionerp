<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_disbursement_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method');
            $table->string('reference_no');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['purchase_order_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_payments');
    }
};
