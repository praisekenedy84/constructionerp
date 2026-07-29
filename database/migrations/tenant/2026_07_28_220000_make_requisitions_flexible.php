<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropForeign(['boq_item_id']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('boq_item_id')->nullable()->change();
            $table->string('resource_type')->default('materials')->after('department');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreign('boq_item_id')
                ->references('id')
                ->on('boq_items')
                ->nullOnDelete();
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropForeign(['boq_item_id']);
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('boq_item_id')->nullable()->change();
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('boq_item_id')
                ->constrained('inventory_items')
                ->nullOnDelete();
            $table->string('unit')->nullable()->after('description');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreign('boq_item_id')
                ->references('id')
                ->on('boq_items')
                ->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['boq_item_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('boq_item_id')->nullable()->change();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('boq_item_id')
                ->references('id')
                ->on('boq_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('requisitions')->whereNull('boq_item_id')->delete();
        DB::table('requisition_items')->whereNull('boq_item_id')->delete();
        DB::table('purchase_orders')->whereNull('boq_item_id')->delete();

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['boq_item_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('boq_item_id')->nullable(false)->change();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('boq_item_id')
                ->references('id')
                ->on('boq_items')
                ->restrictOnDelete();
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropForeign(['boq_item_id']);
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('unit');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('boq_item_id')->nullable(false)->change();
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreign('boq_item_id')
                ->references('id')
                ->on('boq_items')
                ->restrictOnDelete();
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropForeign(['boq_item_id']);
            $table->dropColumn('resource_type');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('boq_item_id')->nullable(false)->change();
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreign('boq_item_id')
                ->references('id')
                ->on('boq_items')
                ->restrictOnDelete();
        });
    }
};
