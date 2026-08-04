<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('requisition_category_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('requisition_categories')
                ->nullOnDelete();
            $table->string('recipient_name')->nullable()->after('details');
            $table->foreignId('position_id')
                ->nullable()
                ->after('recipient_name')
                ->constrained('positions')
                ->nullOnDelete();
            $table->string('recipient_position')->nullable()->after('position_id');
        });

        // Prefer first linked category / recipient; fall back to header scalars.
        $items = DB::table('requisition_items as ri')
            ->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->select([
                'ri.id',
                'r.id as requisition_id',
                'r.requisition_category_id',
                'r.recipient_name',
                'r.recipient_position',
                'r.position_id',
            ])
            ->get();

        foreach ($items as $item) {
            $categoryId = DB::table('requisition_requisition_category')
                ->where('requisition_id', $item->requisition_id)
                ->orderBy('sort_order')
                ->value('requisition_category_id')
                ?? $item->requisition_category_id;

            $recipient = DB::table('requisition_recipients')
                ->where('requisition_id', $item->requisition_id)
                ->orderBy('sort_order')
                ->first();

            DB::table('requisition_items')->where('id', $item->id)->update([
                'requisition_category_id' => $categoryId,
                'recipient_name' => $recipient?->name ?? $item->recipient_name,
                'position_id' => $recipient?->position_id ?? $item->position_id,
                'recipient_position' => $recipient?->position_name ?? $item->recipient_position,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_category_id');
            $table->dropConstrainedForeignId('position_id');
            $table->dropColumn(['recipient_name', 'recipient_position']);
        });
    }
};
