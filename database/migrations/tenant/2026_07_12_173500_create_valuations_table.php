<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('certificate_no');
            $table->decimal('gross_value', 15, 2);
            $table->decimal('total_deductions', 15, 2);
            $table->decimal('net_value', 15, 2);
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'certificate_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuations');
    }
};
