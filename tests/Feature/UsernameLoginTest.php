<?php

namespace Tests\Feature;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CompanyAccessService;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_contacts_resolve_only_the_requested_username_and_updates_preserve_identity(): void
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'username-a']);
        $first = app(AuthService::class)->createUser($a, ['name' => 'Director', 'username' => 'john.alpha', 'email' => 'shared@example.com', 'phone' => '+255123456789', 'password' => 'password', 'role' => 'System Administrator']);
        $other = app(AuthService::class)->createUser($a, ['name' => 'Engineer', 'username' => 'john.engineer', 'email' => 'shared@example.com', 'phone' => '+255123456789', 'password' => 'different-password', 'role' => 'Site Engineer']);
        tenancy()->end();
        $b = Tenant::create(['name' => 'B', 'slug' => 'username-b']);
        app(AuthService::class)->createUser($b, ['name' => 'Finance', 'username' => 'john.beta', 'email' => 'shared@example.com', 'phone' => '+255123456789', 'password' => 'other-password', 'role' => 'Finance Manager']);
        tenancy()->end();

        $this->post('/login', ['username' => 'JOHN.BETA', 'password' => 'other-password'])->assertRedirect('/dashboard');
        $this->assertSame($b->id, session('tenant_id'));
        $this->assertTrue(auth()->user()->hasRole('Finance Manager'));
        $this->post('/logout');
        $this->post('/login', ['username' => 'shared@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertSame($a->id, session('tenant_id'));
        $this->post('/logout');
        $this->post('/login', ['username' => 'john.alpha', 'password' => 'password'])->assertRedirect('/dashboard');
        $identity = CentralUser::where('username', 'john.engineer')->firstOrFail();
        $a->run(function () use ($first, $other) {
            app(UserManagementService::class)->update($first, $other, ['username' => 'john.changed', 'email' => 'changed@example.com', 'phone' => '+255123456789']);
        });
        $this->assertSame($identity->id, CentralUser::where('username', 'john.changed')->firstOrFail()->id);
        $this->assertSame('shared@example.com', CentralUser::where('username', 'john.beta')->firstOrFail()->email);
        $this->assertSame('shared@example.com', CentralUser::where('username', 'john.alpha')->firstOrFail()->email);
    }

    public function test_case_insensitive_duplicate_username_is_rejected_without_deleting_existing_identity(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'username-duplicate']);
        $data = ['name' => 'One', 'username' => 'unique.name', 'email' => 'one@example.com', 'password' => 'password'];
        app(AuthService::class)->createUser($tenant, $data);
        try {
            app(AuthService::class)->createUser($tenant, [...$data, 'username' => 'UNIQUE.NAME', 'email' => 'two@example.com']);
            $this->fail('Duplicate username accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->errors());
        }
        $this->assertSame(1, CentralUser::where('username', 'unique.name')->count());
    }

    public function test_tenant_migration_backfills_username_and_keeps_password_and_identity(): void
    {
        $tenant = Tenant::create(['name' => 'Legacy', 'slug' => 'username-legacy']);
        $identity = CentralUser::create(['username' => 'legacy@example.com', 'email' => 'legacy@example.com', 'tenant_id' => $tenant->id]);
        $tenant->run(function () use ($identity) {
            Schema::table('users', function ($table) {
                $table->dropUnique(['username']);
                $table->dropColumn(['username', 'phone']);
                $table->unique('email');
            });
            $hash = Hash::make('unchanged-password');
            $id = DB::table('users')->insertGetId(['name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => $hash]);
            $migration = require database_path('migrations/tenant/2026_11_07_000001_add_usernames_to_users.php');
            $migration->up();
            $user = User::findOrFail($id);
            $this->assertSame('legacy@example.com', $user->username);
            $this->assertSame($hash, $user->password);
            $this->assertEquals($id, $identity->fresh()->tenant_user_id);
        });
    }

    public function test_deleting_shared_username_only_removes_that_company_account(): void
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'shared-username-a']);
        $admin = app(AuthService::class)->createUser($a, ['name' => 'Admin', 'username' => 'scoped.admin', 'email' => 'admin@scoped.local', 'password' => 'password', 'role' => 'System Administrator']);
        $member = app(AuthService::class)->createUser($a, ['name' => 'Member A', 'username' => 'scoped.member', 'email' => 'shared@scoped.local', 'password' => 'password-a', 'role' => 'Site Engineer']);
        tenancy()->end();
        $b = Tenant::create(['name' => 'B', 'slug' => 'shared-username-b']);
        app(AuthService::class)->createUser($b, ['name' => 'Member B', 'username' => 'scoped.member', 'email' => 'shared@scoped.local', 'password' => 'password-b', 'role' => 'Finance Manager']);
        tenancy()->end();
        $this->post('/login', ['username' => 'scoped.member', 'password' => 'password-a'])->assertRedirect('/dashboard');
        $this->assertCount(1, app(CompanyAccessService::class)->companies());
        $this->assertSame($a->id, session('tenant_id'));
        $this->post('/logout');
        $a->run(fn () => app(UserManagementService::class)->delete($admin, $member));
        $this->assertFalse(CentralUser::where('tenant_id', $a->id)->where('username', 'scoped.member')->exists());
        $this->assertTrue(CentralUser::where('tenant_id', $b->id)->where('username', 'scoped.member')->exists());
        $this->post('/login', ['username' => 'scoped.member', 'password' => 'password-b'])->assertRedirect('/dashboard');
        $this->assertSame($b->id, session('tenant_id'));
    }
}
