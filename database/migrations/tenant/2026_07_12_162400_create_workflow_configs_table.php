<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedInteger('level');
            $table->string('role_name');
            $table->decimal('threshold_min', 15, 2);
            $table->decimal('threshold_max', 15, 2)->nullable();
            $table->unsignedInteger('escalation_hours')->default(48);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_configs');
    }
};
