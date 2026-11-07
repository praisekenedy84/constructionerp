<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'username']);
            $table->dropUnique(['username']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Usernames may now be shared across companies. A forward data migration is required to restore global uniqueness.');
    }
};
