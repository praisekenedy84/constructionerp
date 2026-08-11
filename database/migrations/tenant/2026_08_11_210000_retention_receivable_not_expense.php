<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            $table->decimal('retention_receivable_amount', 15, 2)
                ->default(0)
                ->after('retention_released_amount');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['phase_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('phase_id')->nullable()->change();
            $table->unique('phase_id');
        });

        DB::table('expenses')
            ->whereNotNull('valuation_id')
            ->where('category', 'direct')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where('sub_type', 'Retention')
                    ->orWhere('description', 'like', '%compliance — Retention');
            })
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            $table->dropColumn('retention_receivable_amount');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['phase_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('phase_id')->nullable(false)->change();
            $table->unique('phase_id');
        });
    }
};
