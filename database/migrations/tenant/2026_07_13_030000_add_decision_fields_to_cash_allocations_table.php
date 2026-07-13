<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->timestamp('decided_at')->nullable()->after('received_at');
            $table->text('rejection_reason')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->dropColumn(['decided_at', 'rejection_reason']);
        });
    }
};
