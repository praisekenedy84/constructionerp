<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_allocation_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method');
            $table->string('payee')->nullable();
            $table->foreignId('disbursed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('disbursed_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_disbursements');
    }
};
