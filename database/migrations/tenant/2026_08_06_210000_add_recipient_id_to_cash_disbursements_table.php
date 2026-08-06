<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->foreignId('recipient_id')
                ->nullable()
                ->after('requisition_id')
                ->constrained('recipients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
        });
    }
};
