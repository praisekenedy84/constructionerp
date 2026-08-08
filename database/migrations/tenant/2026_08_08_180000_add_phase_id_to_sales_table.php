<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['project_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('phase_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_phases')
                ->restrictOnDelete();
            $table->unique('phase_id');
            $table->index(['project_id', 'status']);
        });

        $phases = DB::table('project_phases')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'project_id', 'sequence_no']);

        $projects = DB::table('projects')
            ->whereNull('deleted_at')
            ->get(['id', 'code'])
            ->keyBy('id');

        $now = now();

        foreach ($phases as $phase) {
            $exists = DB::table('sales')->where('phase_id', $phase->id)->exists();
            if ($exists) {
                continue;
            }

            $project = $projects->get($phase->project_id);
            $projectCode = $project?->code ?? 'PRJ';
            $normalized = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $projectCode) ?: 'PRJ');

            DB::table('sales')->insert([
                'project_id' => $phase->project_id,
                'phase_id' => $phase->id,
                'sale_code' => 'SALE-'.$normalized.'-P'.$phase->sequence_no.'-'.$phase->id,
                'status' => 'open',
                'contract_amount' => null,
                'profit_amount' => null,
                'collected_amount' => '0.00',
                'converted_at' => null,
                'converted_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Remove open legacy project-only sales (no phase). Keep converted ones for collection.
        DB::table('sales')
            ->whereNull('phase_id')
            ->where('status', 'open')
            ->delete();
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['phase_id']);
            $table->dropForeign(['phase_id']);
            $table->dropColumn('phase_id');
            $table->dropIndex(['project_id', 'status']);
        });

        // Restore one sale per project for rollback compatibility.
        $projectIds = DB::table('sales')->distinct()->pluck('project_id');
        foreach ($projectIds as $projectId) {
            $keepId = DB::table('sales')
                ->where('project_id', $projectId)
                ->orderBy('id')
                ->value('id');

            DB::table('sales')
                ->where('project_id', $projectId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->unique('project_id');
        });
    }
};
