<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $defaults = [
            'pcs',
            'bag',
            'kg',
            'ton',
            'm',
            'm²',
            'm³',
            'L',
            'day',
            'hour',
            'person',
            'trip',
            'set',
            'ls',
        ];

        $existing = [];
        if (Schema::hasTable('requisition_items') && Schema::hasColumn('requisition_items', 'unit')) {
            $existing = DB::table('requisition_items')
                ->whereNotNull('unit')
                ->where('unit', '!=', '')
                ->distinct()
                ->orderBy('unit')
                ->pluck('unit')
                ->all();
        }

        $names = collect([...$defaults, ...$existing])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();

        $rows = [];
        foreach ($names as $index => $name) {
            $rows[] = [
                'name' => $name,
                'description' => null,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('units')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
