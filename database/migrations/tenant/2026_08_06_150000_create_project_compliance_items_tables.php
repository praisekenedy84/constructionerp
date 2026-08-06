<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_compliance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compliance_rule_id')->constrained('compliance_rules');
            $table->string('calculation_type');
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('allocation_level')->default('contract');
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->foreignId('valuation_id')->nullable()->constrained('valuations')->nullOnDelete();
            $table->timestamp('attached_at');
            $table->timestamp('migrated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'compliance_rule_id'], 'project_compliance_items_project_rule_unique');
            $table->index(['project_id', 'allocation_level']);
        });

        Schema::create('project_compliance_item_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_compliance_item_id')
                ->constrained('project_compliance_items')
                ->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->foreignId('valuation_id')->nullable()->constrained('valuations')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_compliance_item_events');
        Schema::dropIfExists('project_compliance_items');
    }
};
