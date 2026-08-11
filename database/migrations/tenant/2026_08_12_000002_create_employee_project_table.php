<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'employee_id']);
        });

        $now = now();

        $rows = DB::table('employees')
            ->whereNotNull('project_id')
            ->get(['id', 'project_id'])
            ->map(fn ($employee) => [
                'employee_id' => $employee->id,
                'project_id' => $employee->project_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('employee_project')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_project');
    }
};
