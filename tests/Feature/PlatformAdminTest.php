<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private function createPlatformAdmin(string $email = 'platform@crf.local'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Platform Operator',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function provisionTenant(string $slug = 'acme'): Tenant
    {
        return Tenant::create([
            'name' => 'Acme Construction',
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    public function test_platform_login_and_dashboard(): void
    {
        $this->createPlatformAdmin();

        $this->post('/platform/login', [
            'email' => 'platform@crf.local',
            'password' => 'password',
        ])->assertRedirect('/platform');

        $this->get('/platform')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Dashboard')
                ->has('stats.total_tenants')
            );
    }

    public function test_platform_admin_can_provision_tenant(): void
    {
        $admin = $this->createPlatformAdmin('provisioner@crf.local');
        $this->post('/platform/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->post('/platform/tenants', [
            'name' => 'Beta Builders',
            'slug' => 'beta-builders',
            'admin_name' => 'Beta Admin',
            'admin_email' => 'admin@beta.local',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'beta-builders')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('active', $tenant->status);
    }

    public function test_suspended_tenant_blocks_login(): void
    {
        $tenant = $this->provisionTenant();

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Tenant User',
            'email' => 'user@acme.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $tenant->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_reason' => 'Non-payment',
        ]);

        $this->post('/login', [
            'email' => 'user@acme.local',
            'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_platform_admin_can_view_tenant_users(): void
    {
        $tenant = $this->provisionTenant();

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Tenant Admin',
            'email' => 'admin@acme.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->createPlatformAdmin();
        $this->post('/platform/login', [
            'email' => 'platform@crf.local',
            'password' => 'password',
        ]);

        $this->get("/platform/tenants/{$tenant->id}/users")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Tenants/Users')
                ->has('users.data', 1)
                ->where('users.data.0.email', 'admin@acme.local')
                ->where('users.data.0.roles', ['System Administrator'])
                ->has('filters')
            );
    }

    public function test_platform_admin_can_lock_user(): void
    {
        $tenant = $this->provisionTenant();

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Lockable User',
            'email' => 'lock@acme.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();

        $this->createPlatformAdmin();
        $this->post('/platform/login', [
            'email' => 'platform@crf.local',
            'password' => 'password',
        ]);

        tenancy()->initialize($tenant);
        $userId = \App\Models\User::where('email', 'lock@acme.local')->value('id');
        tenancy()->end();

        $this->post("/platform/tenants/{$tenant->id}/users/{$userId}/lock", [
            'reason' => 'Policy violation',
        ])->assertRedirect();

        tenancy()->initialize($tenant);
        $user = \App\Models\User::find($userId);
        $this->assertNotNull($user->locked_at);
        tenancy()->end();

        $this->post('/login', [
            'email' => 'lock@acme.local',
            'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_platform_admin_can_impersonate_tenant_user(): void
    {
        $tenant = $this->provisionTenant();

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Target User',
            'email' => 'target@acme.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();

        $this->createPlatformAdmin();
        $this->post('/platform/login', [
            'email' => 'platform@crf.local',
            'password' => 'password',
        ]);

        tenancy()->initialize($tenant);
        $userId = \App\Models\User::where('email', 'target@acme.local')->value('id');
        tenancy()->end();

        $this->post("/platform/tenants/{$tenant->id}/users/{$userId}/impersonate")
            ->assertRedirect('/dashboard');

        $this->assertAuthenticated('web');
        $this->assertNotNull(session('platform_impersonator_id'));
        $this->assertSame($tenant->id, session('tenant_id'));
    }
}
