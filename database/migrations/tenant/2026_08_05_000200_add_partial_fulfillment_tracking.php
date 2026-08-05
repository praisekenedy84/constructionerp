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
            $table->string('fulfillment_scope')->nullable()->after('fulfillment_type');
            $table->decimal('fulfilled_amount', 15, 2)->default(0)->after('amended_amount');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('fulfilled_quantity', 15, 4)->default(0)->after('quantity');
        });

        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->foreignId('requisition_item_id')
                ->nullable()
                ->after('requisition_id')
                ->constrained('requisition_items')
                ->nullOnDelete();
        });

        DB::table('requisitions')
            ->whereIn('status', ['fulfilled', 'closed'])
            ->update([
                'fulfillment_scope' => 'whole',
                'fulfilled_amount' => DB::raw('COALESCE(amended_amount, original_amount)'),
            ]);

        DB::table('requisition_items')
            ->whereIn(
                'requisition_id',
                DB::table('requisitions')->whereIn('status', ['fulfilled', 'closed'])->select('id')
            )
            ->update(['fulfilled_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('inventory_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_item_id');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn('fulfilled_quantity');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_scope', 'fulfilled_amount']);
        });
    }
};
