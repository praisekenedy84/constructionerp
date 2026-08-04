<?php

use App\Enums\ComplianceRuleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->string('rule_type')->default(ComplianceRuleType::Other->value)->after('description');
        });

        DB::table('compliance_rules')
            ->whereRaw('LOWER(name) LIKE ?', ['%retention%'])
            ->update(['rule_type' => ComplianceRuleType::Retention->value]);

        DB::table('compliance_rules')
            ->whereRaw('LOWER(name) LIKE ?', ['%advance%'])
            ->update(['rule_type' => ComplianceRuleType::AdvanceRecovery->value]);

        DB::table('compliance_rules')
            ->whereRaw('LOWER(name) IN (?, ?)', ['wht', 'withholding tax'])
            ->update(['rule_type' => ComplianceRuleType::Wht->value]);
    }

    public function down(): void
    {
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->dropColumn('rule_type');
        });
    }
};
