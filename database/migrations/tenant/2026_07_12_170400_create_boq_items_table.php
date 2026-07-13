<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('boq_sections')->cascadeOnDelete();
            $table->text('description');
            $table->string('unit');
            $table->string('category');
            $table->decimal('budgeted_qty', 15, 4);
            $table->decimal('unit_rate', 15, 2);
            $table->decimal('budgeted_amount', 15, 2);
            $table->decimal('reserved_qty', 15, 4)->default(0);
            $table->decimal('consumed_qty', 15, 4)->default(0);
            $table->decimal('requested_qty', 15, 4)->default(0);
            $table->decimal('approved_qty', 15, 4)->default(0);
            $table->decimal('procured_qty', 15, 4)->default(0);
            $table->decimal('received_qty', 15, 4)->default(0);
            $table->decimal('issued_qty', 15, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_items');
    }
};
