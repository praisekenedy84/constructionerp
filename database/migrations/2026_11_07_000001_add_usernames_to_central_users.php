<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->string('username')->nullable()->unique();
            $table->unsignedBigInteger('tenant_user_id')->nullable();
        });
        foreach (DB::table('central_users')->orderBy('id')->get() as $user) {
            $base = strtolower(trim($user->email));
            $username = $base;
            while (DB::table('central_users')->where('username', $username)->exists()) {
                $username = substr($base, 0, 230).'.'.$user->id;
            }
            DB::table('central_users')->where('id', $user->id)->update(['username' => $username]);
        }
    }

    public function down(): void
    {
        // Reused emails cannot safely be made unique again.
        throw new RuntimeException('Username migration requires a forward migration to reverse safely.');
    }
};
