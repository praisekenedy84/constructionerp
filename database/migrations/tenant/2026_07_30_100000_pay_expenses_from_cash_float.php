<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->unsignedBigInteger('requisition_id')->nullable()->change();
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->foreign('requisition_id')
                ->references('id')
                ->on('requisitions')
                ->restrictOnDelete();

            $table->foreignId('expense_id')
                ->nullable()
                ->after('requisition_id')
                ->constrained('expenses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropForeign(['expense_id']);
            $table->dropColumn('expense_id');
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->unsignedBigInteger('requisition_id')->nullable(false)->change();
        });

        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->foreign('requisition_id')
                ->references('id')
                ->on('requisitions')
                ->restrictOnDelete();
        });
    }
};
