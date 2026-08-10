<?php

use App\Enums\ComplianceRuleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ensure every tenant has a system Retention compliance rule.
     *
     * Retention hold/release/forfeit only runs when rule_type = retention.
     * Seeding a canonical active rule avoids tenants creating a "Retention"
     * entry with the default "other" type and miscalculating phase budgets.
     */
    public function up(): void
    {
        $now = now();
        $name = 'Retention';
        $description = 'Contract retention held from phase IPCs until release or forfeit.';
        $ruleType = ComplianceRuleType::Retention->value;

        $existing = DB::table('compliance_rules')
            ->where('name', $name)
            ->first();

        if ($existing === null) {
            DB::table('compliance_rules')->insert([
                'name' => $name,
                'description' => $description,
                'rule_type' => $ruleType,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            return;
        }

        DB::table('compliance_rules')
            ->where('id', $existing->id)
            ->update([
                'description' => $existing->description ?: $description,
                'rule_type' => $ruleType,
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Keep the seeded Retention rule; removing it would break tenants
        // that already attached it to projects or IPCs.
    }
};
