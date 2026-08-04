<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('position_id')
                ->nullable()
                ->constrained('positions')
                ->nullOnDelete();
            $table->string('position_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['requisition_id', 'sort_order']);
        });

        Schema::create('requisition_requisition_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('requisition_category_id')->constrained('requisition_categories')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['requisition_id', 'requisition_category_id'],
                'req_category_unique'
            );
            $table->index(['requisition_id', 'sort_order']);
        });

        $now = now();

        $recipientRows = [];
        DB::table('requisitions')
            ->select(['id', 'recipient_name', 'recipient_position', 'position_id'])
            ->orderBy('id')
            ->chunkById(200, function ($requisitions) use (&$recipientRows, $now) {
                foreach ($requisitions as $requisition) {
                    $name = trim((string) ($requisition->recipient_name ?? ''));
                    $hasPosition = $requisition->position_id || trim((string) ($requisition->recipient_position ?? '')) !== '';

                    if ($name === '' && ! $hasPosition) {
                        continue;
                    }

                    $recipientRows[] = [
                        'requisition_id' => $requisition->id,
                        'name' => $name !== '' ? $name : '—',
                        'position_id' => $requisition->position_id,
                        'position_name' => $requisition->recipient_position,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            });

        if ($recipientRows !== []) {
            foreach (array_chunk($recipientRows, 500) as $chunk) {
                DB::table('requisition_recipients')->insert($chunk);
            }
        }

        $categoryRows = [];
        DB::table('requisitions')
            ->select(['id', 'requisition_category_id'])
            ->whereNotNull('requisition_category_id')
            ->orderBy('id')
            ->chunkById(200, function ($requisitions) use (&$categoryRows, $now) {
                foreach ($requisitions as $requisition) {
                    $categoryRows[] = [
                        'requisition_id' => $requisition->id,
                        'requisition_category_id' => $requisition->requisition_category_id,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            });

        if ($categoryRows !== []) {
            foreach (array_chunk($categoryRows, 500) as $chunk) {
                DB::table('requisition_requisition_category')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_requisition_category');
        Schema::dropIfExists('requisition_recipients');
    }
};
