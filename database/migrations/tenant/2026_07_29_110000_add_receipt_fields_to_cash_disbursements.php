<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->string('reference_no')->nullable()->after('payee');
            $table->string('account_name')->nullable()->after('payee');
        });
    }

    public function down(): void
    {
        Schema::table('cash_disbursements', function (Blueprint $table) {
            $table->dropColumn(['reference_no', 'account_name']);
        });
    }
};
