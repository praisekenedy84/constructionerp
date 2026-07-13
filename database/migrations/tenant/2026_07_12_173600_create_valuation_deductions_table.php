<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuation_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valuation_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type');
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_deductions');
    }
};
