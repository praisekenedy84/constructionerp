<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('equipment_assignments')->nullOnDelete();
            $table->decimal('liters', 8, 2);
            $table->decimal('cost', 10, 2);
            $table->date('date');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_fuel_logs');
    }
};
