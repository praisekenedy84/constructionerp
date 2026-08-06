<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('status')->default('present'); // present | absent
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['recipient_id', 'project_id', 'date']);
            $table->index(['project_id', 'date']);
            $table->index(['recipient_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_attendances');
    }
};
