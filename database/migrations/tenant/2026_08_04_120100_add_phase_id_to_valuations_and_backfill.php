<?php

use App\Enums\PhaseStatus;
use App\Enums\RetentionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->foreignId('phase_id')->nullable()->after('project_id')->constrained('project_phases')->nullOnDelete();
        });

        $projects = DB::table('projects')->select(['id', 'contract_amount', 'net_budget'])->get();
        foreach ($projects as $project) {
            $phaseId = DB::table('project_phases')->insertGetId([
                'project_id' => $project->id,
                'sequence_no' => 1,
                'name' => 'Phase 1 (Legacy)',
                'status' => PhaseStatus::Succeeded->value,
                'disbursed_amount' => $project->contract_amount,
                'retention_held_amount' => '0.00',
                'retention_released_amount' => '0.00',
                'retention_forfeited_amount' => '0.00',
                'other_deductions_amount' => '0.00',
                'phase_net_budget' => $project->net_budget,
                'retention_status' => RetentionStatus::None->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('valuations')
                ->where('project_id', $project->id)
                ->update(['phase_id' => $phaseId]);
        }

        Schema::table('valuations', function (Blueprint $table) {
            $table->foreignId('phase_id')->nullable(false)->change();
            $table->dropUnique('valuations_project_id_certificate_no_unique');
            $table->unique(['phase_id', 'certificate_no']);
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropUnique(['phase_id', 'certificate_no']);
            $table->unique(['project_id', 'certificate_no']);
            $table->dropConstrainedForeignId('phase_id');
        });

        DB::table('project_phases')->where('name', 'Phase 1 (Legacy)')->delete();
    }
};
