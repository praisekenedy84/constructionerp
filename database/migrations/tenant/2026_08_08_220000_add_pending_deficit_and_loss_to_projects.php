<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('pending_deficit', 15, 2)->default(0)->after('net_budget');
            $table->timestamp('marked_loss_at')->nullable()->after('status');
            $table->foreignId('marked_loss_by')->nullable()->after('marked_loss_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marked_loss_by');
            $table->dropColumn(['pending_deficit', 'marked_loss_at']);
        });
    }
};
