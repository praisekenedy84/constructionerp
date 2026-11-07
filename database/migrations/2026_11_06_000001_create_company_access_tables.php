<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('central_user_id')->constrained('central_users')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('granted_by');
            $table->timestamps();
            $table->unique(['central_user_id', 'tenant_id']);
        });
        Schema::create('company_access_events', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->unsignedBigInteger('central_user_id')->nullable();
            $table->string('tenant_id');
            $table->unsignedBigInteger('platform_admin_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_access_events');
        Schema::dropIfExists('company_access_grants');
    }
};
