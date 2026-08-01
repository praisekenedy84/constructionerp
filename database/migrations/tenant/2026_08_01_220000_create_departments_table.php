<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
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
            'Site Operations',
            'Procurement',
            'Site Stores',
            'Plant',
            'Logistics',
            'Finance',
            'Administration',
        ];

        $existing = DB::table('requisitions')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->all();

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
            DB::table('departments')->insert($rows);
        }

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('department')
                ->constrained('departments')
                ->nullOnDelete();
        });

        $departments = DB::table('departments')->get(['id', 'name']);
        foreach ($departments as $department) {
            DB::table('requisitions')
                ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($department->name))])
                ->whereNull('department_id')
                ->update(['department_id' => $department->id]);
        }
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
