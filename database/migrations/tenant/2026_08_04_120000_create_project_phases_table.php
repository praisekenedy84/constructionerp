<?php

use App\Enums\PhaseStatus;
use App\Enums\RetentionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->string('name');
            $table->string('status')->default(PhaseStatus::Pending->value);
            $table->decimal('disbursed_amount', 15, 2)->default(0);
            $table->decimal('retention_held_amount', 15, 2)->default(0);
            $table->decimal('retention_released_amount', 15, 2)->default(0);
            $table->decimal('retention_forfeited_amount', 15, 2)->default(0);
            $table->decimal('other_deductions_amount', 15, 2)->default(0);
            $table->decimal('phase_net_budget', 15, 2)->default(0);
            $table->string('retention_status')->default(RetentionStatus::None->value);
            $table->timestamp('retention_released_at')->nullable();
            $table->timestamp('retention_forfeited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'sequence_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_phases');
    }
};
