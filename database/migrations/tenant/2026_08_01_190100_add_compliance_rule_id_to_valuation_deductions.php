<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuation_deductions', function (Blueprint $table) {
            $table->foreignId('compliance_rule_id')
                ->nullable()
                ->after('valuation_id')
                ->constrained('compliance_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('valuation_deductions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compliance_rule_id');
        });
    }
};
