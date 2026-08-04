<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
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
            'Site Foreman',
            'Site Engineer',
            'Project Manager',
            'Storekeeper',
            'Quantity Surveyor',
            'Supervisor',
            'Labourer',
            'Driver',
        ];

        $existing = [];
        if (Schema::hasColumn('requisitions', 'recipient_position')) {
            $existing = DB::table('requisitions')
                ->whereNotNull('recipient_position')
                ->where('recipient_position', '!=', '')
                ->distinct()
                ->orderBy('recipient_position')
                ->pluck('recipient_position')
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
            DB::table('positions')->insert($rows);
        }

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('position_id')
                ->nullable()
                ->after('recipient_position')
                ->constrained('positions')
                ->nullOnDelete();
        });

        $positions = DB::table('positions')->get(['id', 'name']);
        foreach ($positions as $position) {
            DB::table('requisitions')
                ->whereRaw('LOWER(TRIM(recipient_position)) = ?', [mb_strtolower(trim($position->name))])
                ->whereNull('position_id')
                ->update(['position_id' => $position->id]);
        }
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
    }
};
