<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('client');
            $table->string('location');
            $table->decimal('contract_amount', 15, 2);
            $table->decimal('wht_percentage', 5, 2);
            $table->decimal('net_budget', 15, 2);
            $table->decimal('physical_progress_pct', 5, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('planning');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
