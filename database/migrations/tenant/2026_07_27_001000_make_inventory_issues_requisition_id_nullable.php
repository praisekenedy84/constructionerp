<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
        });

        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->unsignedBigInteger('requisition_id')->nullable()->change();
        });

        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->foreign('requisition_id')
                ->references('id')
                ->on('requisitions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
        });

        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->unsignedBigInteger('requisition_id')->nullable(false)->change();
        });

        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->foreign('requisition_id')
                ->references('id')
                ->on('requisitions')
                ->restrictOnDelete();
        });
    }
};
