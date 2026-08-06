<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('recipient_id')
                ->nullable()
                ->after('details')
                ->constrained('recipients')
                ->nullOnDelete();
        });

        Schema::table('requisition_recipients', function (Blueprint $table) {
            $table->foreignId('recipient_id')
                ->nullable()
                ->after('requisition_id')
                ->constrained('recipients')
                ->nullOnDelete();
            $table->string('phone')->nullable()->after('name');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('recipient_id')
                ->nullable()
                ->after('requestor_id')
                ->constrained('recipients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
        });

        Schema::table('requisition_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
            $table->dropColumn('phone');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
        });
    }
};
