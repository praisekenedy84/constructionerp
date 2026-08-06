<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('purchase_order_no')->nullable()->unique()->after('id');
            $table->foreignId('equipment_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('equipment')
                ->nullOnDelete();
            $table->date('purchase_date')->nullable()->after('total_amount');
            $table->foreignId('created_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->timestamps();
        });

        Schema::table('equipment_maintenances', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->unique()
                ->after('equipment_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('purchase_order_items');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('equipment_id');
            $table->dropUnique(['purchase_order_no']);
            $table->dropColumn(['purchase_order_no', 'purchase_date']);
        });
    }
};
