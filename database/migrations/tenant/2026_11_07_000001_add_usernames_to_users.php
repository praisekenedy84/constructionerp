<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->string('username')->nullable()->unique();
            $table->string('phone', 50)->nullable();
        });
        $central = DB::connection(config('tenancy.database.central_connection'));
        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $identity = $central->table('central_users')->where('tenant_id', tenant('id'))->where('email', $user->email)->first();
            $username = $identity?->username ?? 'legacy.'.tenant('id').'.'.$user->id;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            if ($identity) {
                $central->table('central_users')->where('id', $identity->id)->update(['tenant_user_id' => $user->id]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Username migration requires a forward migration to reverse safely.');
    }
};
