<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'req_status_updated_idx');
            $table->index(['project_id', 'status'], 'req_project_status_idx');
            $table->index(
                ['status', 'addressed_to', 'fulfillment_type'],
                'req_finance_queue_idx',
            );
        });

        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->index(['status', 'requested_at'], 'cash_alloc_status_requested_idx');
            $table->index(['project_id', 'status'], 'cash_alloc_project_status_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['category', 'expense_date'], 'expenses_category_date_idx');
            $table->index(['project_id', 'expense_date'], 'expenses_project_date_idx');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->index(['status', 'assigned_at'], 'approval_status_assigned_idx');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->index('recipient_id', 'req_items_recipient_idx');
        });

        Schema::table('requisition_recipients', function (Blueprint $table) {
            $table->index('recipient_id', 'req_recipients_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_recipients', function (Blueprint $table) {
            $table->dropIndex('req_recipients_recipient_idx');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropIndex('req_items_recipient_idx');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropIndex('approval_status_assigned_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_category_date_idx');
            $table->dropIndex('expenses_project_date_idx');
        });

        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->dropIndex('cash_alloc_status_requested_idx');
            $table->dropIndex('cash_alloc_project_status_idx');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropIndex('req_status_updated_idx');
            $table->dropIndex('req_project_status_idx');
            $table->dropIndex('req_finance_queue_idx');
        });
    }
};
