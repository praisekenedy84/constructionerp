<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuation_deductions', function (Blueprint $table) {
            $table->string('name')->nullable()->after('valuation_id');
            $table->string('calculation_type')->default('rate_percent')->after('name');
            $table->decimal('fixed_amount', 15, 2)->nullable()->after('rate');
        });

        $rows = DB::table('valuation_deductions')->whereNull('name')->get();

        foreach ($rows as $row) {
            $label = ucwords(str_replace('_', ' ', (string) $row->rule_type));
            DB::table('valuation_deductions')->where('id', $row->id)->update([
                'name' => $label !== '' ? $label : 'Compliance',
                'calculation_type' => 'rate_percent',
            ]);
        }

        Schema::table('valuation_deductions', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->decimal('rate', 5, 2)->nullable()->change();
            $table->string('rule_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('valuation_deductions', function (Blueprint $table) {
            $table->string('rule_type')->nullable(false)->change();
            $table->decimal('rate', 5, 2)->nullable(false)->change();
            $table->dropColumn(['name', 'calculation_type', 'fixed_amount']);
        });
    }
};
