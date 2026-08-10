<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_categories', function (Blueprint $table) {
            $table->string('expense_type')->default('direct')->after('description');
        });

        // Existing seeded categories (Materials, Cash, …) are project / direct by default.
        // Salaries is administrative payroll overhead.
        DB::table('requisition_categories')
            ->whereRaw('LOWER(name) = ?', ['salaries'])
            ->update(['expense_type' => 'indirect']);

        Schema::table('requisition_categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['name', 'expense_type']);
        });
    }

    public function down(): void
    {
        Schema::table('requisition_categories', function (Blueprint $table) {
            $table->dropUnique(['name', 'expense_type']);
            $table->unique('name');
            $table->dropColumn('expense_type');
        });
    }
};
