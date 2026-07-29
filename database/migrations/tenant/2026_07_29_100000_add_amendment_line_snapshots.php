<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('original_quantity', 15, 4)->nullable()->after('line_total');
            $table->decimal('original_unit_cost', 15, 2)->nullable()->after('original_quantity');
            $table->decimal('original_line_total', 15, 2)->nullable()->after('original_unit_cost');
            $table->text('original_description')->nullable()->after('original_line_total');
        });

        Schema::table('requisition_status_histories', function (Blueprint $table) {
            $table->json('amendment_items')->nullable()->after('variance');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_status_histories', function (Blueprint $table) {
            $table->dropColumn('amendment_items');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn([
                'original_quantity',
                'original_unit_cost',
                'original_line_total',
                'original_description',
            ]);
        });
    }
};
