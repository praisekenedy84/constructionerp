<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_no')->unique();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->restrictOnDelete();
            $table->string('department');
            $table->foreignId('requestor_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->string('fulfillment_type');
            $table->decimal('original_amount', 15, 2);
            $table->decimal('amended_amount', 15, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisitions');
    }
};
