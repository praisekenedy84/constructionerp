<?php

namespace Tests\Feature;

use App\Models\CentralUser;
use App\Models\PlatformAdmin;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CompanyAccessService;
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
        ])->assertSessionHasErrors('username');
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
        $userId = User::where('email', 'lock@acme.local')->value('id');
        tenancy()->end();

        $this->post("/platform/tenants/{$tenant->id}/users/{$userId}/lock", [
            'reason' => 'Policy violation',
        ])->assertRedirect();

        tenancy()->initialize($tenant);
        $user = User::find($userId);
        $this->assertNotNull($user->locked_at);
        tenancy()->end();

        $this->post('/login', [
            'email' => 'lock@acme.local',
            'password' => 'password',
        ])->assertSessionHasErrors('username');
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
        $userId = User::where('email', 'target@acme.local')->value('id');
        tenancy()->end();

        $this->post("/platform/tenants/{$tenant->id}/users/{$userId}/impersonate")
            ->assertRedirect('/dashboard');

        $this->assertAuthenticated('web');
        $this->assertNotNull(session('platform_impersonator_id'));
        $this->assertSame($tenant->id, session('tenant_id'));
    }

    public function test_platform_users_page_works_after_impersonation_with_project_context(): void
    {
        $tenant = $this->provisionTenant();

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Target User',
            'email' => 'target@acme.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->initialize($tenant);
        $userId = User::where('email', 'target@acme.local')->value('id');
        $project = Project::create([
            'code' => 'PRJ-001',
            'name' => 'Main Site',
            'client' => 'Client',
            'location' => 'Dar',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'net_budget' => '950000',
            'physical_progress_pct' => '0',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        tenancy()->end();

        $this->createPlatformAdmin();
        $this->post('/platform/login', [
            'email' => 'platform@crf.local',
            'password' => 'password',
        ]);

        $this->post("/platform/tenants/{$tenant->id}/users/{$userId}/impersonate")
            ->assertRedirect('/dashboard');

        session(['current_project_id' => $project->id]);

        // Returning to platform while the web/tenant session still has project
        // context previously crashed with "Database connection [tenant] not configured".
        $this->get("/platform/tenants/{$tenant->id}/users")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Tenants/Users')
                ->where('currentProject', null)
            );
    }

    public function test_platform_admin_can_create_tenant_users_with_reusable_contacts(): void
    {
        $first = $this->provisionTenant('platform-create-first');
        $second = $this->provisionTenant('platform-create-second');
        $admin = $this->createPlatformAdmin();
        $this->actingAs($admin, 'platform');
        $data = ['name' => 'New Engineer', 'username' => 'platform.engineer', 'email' => 'shared@create.local', 'phone' => '+255123456789', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'role' => 'Site Engineer'];
        $this->post("/platform/tenants/{$first->id}/users", $data)->assertRedirect("/platform/tenants/{$first->id}");
        $this->post("/platform/tenants/{$second->id}/users", $data)->assertRedirect();
        $first->run(function () use ($data) {
            $user = User::where('username', $data['username'])->firstOrFail();
            $this->assertTrue(Hash::check($data['password'], $user->password));
            $this->assertTrue($user->hasRole('Site Engineer'));
            $this->assertSame($data['phone'], $user->phone);
        });
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame(2, CentralUser::where('email', $data['email'])->count());
        $this->assertSame(2, app(CompanyAccessService::class)->central()->table('company_access_events')->where('action', 'user_created')->where('platform_admin_id', $admin->id)->count());
        $this->get("/platform/tenants/{$first->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Platform/Tenants/Show')->has('assignable_roles')->has('users.data', 1));
        $this->app['auth']->shouldUse('web');
        $this->post('/login', ['username' => $data['username'], 'password' => $data['password']])->assertRedirect('/auth/companies');
        $this->assertSame($first->id, session('tenant_id'));
    }

    public function test_platform_user_creation_rejects_duplicate_username_privileged_role_and_suspended_tenant(): void
    {
        $tenant = $this->provisionTenant('platform-create-invalid');
        $this->actingAs($this->createPlatformAdmin(), 'platform');
        $data = ['name' => 'Engineer', 'username' => 'platform.unique', 'email' => 'shared@create.local', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'role' => 'Site Engineer'];
        $url = "/platform/tenants/{$tenant->id}/users";
        $this->post($url, $data)->assertRedirect();
        $this->post($url, [...$data, 'username' => 'PLATFORM.UNIQUE'])->assertSessionHasErrors('username');
        $this->post($url, [...$data, 'username' => 'platform.forbidden', 'role' => 'Platform Admin'])->assertSessionHasErrors('role');
        $this->post($url, [...$data, 'username' => 'platform.mismatch', 'password_confirmation' => 'wrong'])->assertSessionHasErrors('password');
        $tenant->update(['status' => 'suspended']);
        $this->post($url, [...$data, 'username' => 'platform.suspended'])->assertForbidden();
        $this->assertSame(1, CentralUser::where('tenant_id', $tenant->id)->count());
    }

    public function test_tenant_administrator_cannot_create_users_through_platform_route(): void
    {
        $tenant = $this->provisionTenant('platform-create-denied');
        $user = app(AuthService::class)->createUser($tenant, ['name' => 'Tenant admin', 'username' => 'tenant.only', 'email' => 'admin@denied.local', 'password' => 'password', 'role' => 'System Administrator']);
        $this->actingAs($user, 'web')->post("/platform/tenants/{$tenant->id}/users", [])->assertRedirect('/platform/login');
        tenancy()->end();
    }

    public function test_platform_admin_can_edit_user_without_changing_password_or_access_grants(): void
    {
        $tenant = $this->provisionTenant('platform-edit');
        $user = app(AuthService::class)->createUser($tenant, ['name' => 'Before', 'username' => 'before.edit', 'email' => 'shared@edit.local', 'password' => 'original-password', 'role' => 'Site Engineer']);
        $hash = $user->password;
        $identity = CentralUser::where('username', 'before.edit')->firstOrFail();
        tenancy()->end();
        $other = $this->provisionTenant('platform-edit-other');
        $admin = $this->createPlatformAdmin();
        app(CompanyAccessService::class)->central()->table('company_access_grants')->insert(['central_user_id' => $identity->id, 'tenant_id' => $other->id, 'granted_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin, 'platform');
        $url = "/platform/tenants/{$tenant->id}/users/{$user->id}";
        $data = ['name' => 'After', 'username' => 'AFTER.EDIT', 'email' => 'changed@edit.local', 'phone' => '+255123456789', 'role' => 'Finance Manager', 'password' => '', 'password_confirmation' => ''];
        $this->from("/platform/tenants/{$tenant->id}")->patch($url, $data)->assertRedirect("/platform/tenants/{$tenant->id}");
        $tenant->run(function () use ($user, $hash) {
            $updated = User::findOrFail($user->id);
            $this->assertSame('after.edit', $updated->username);
            $this->assertSame('After', $updated->name);
            $this->assertSame('+255123456789', $updated->phone);
            $this->assertSame($hash, $updated->password);
            $this->assertTrue($updated->hasRole('Finance Manager'));
        });
        $this->assertSame('after.edit', $identity->fresh()->username);
        $this->assertSame('changed@edit.local', $identity->fresh()->email);
        $this->assertSame(1, app(CompanyAccessService::class)->central()->table('company_access_grants')->where('central_user_id', $identity->id)->count());
        $this->assertFalse(tenancy()->initialized);
        $this->patch($url, [...$data, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertRedirect();
        $tenant->run(fn () => $this->assertTrue(Hash::check('replacement-password', User::findOrFail($user->id)->password)));
        $this->assertSame(2, app(CompanyAccessService::class)->central()->table('company_access_events')->where('action', 'user_updated')->where('platform_admin_id', $admin->id)->count());
    }

    public function test_platform_edit_protects_last_admin_and_rejects_invalid_changes(): void
    {
        $tenant = $this->provisionTenant('platform-edit-validation');
        $user = app(AuthService::class)->createUser($tenant, ['name' => 'Admin', 'username' => 'edit.admin', 'email' => 'admin@edit.local', 'password' => 'password', 'role' => 'System Administrator']);
        app(AuthService::class)->createUser($tenant, ['name' => 'Other', 'username' => 'edit.other', 'email' => 'admin@edit.local', 'password' => 'password', 'role' => 'Site Engineer']);
        tenancy()->end();
        $this->actingAs($this->createPlatformAdmin(), 'platform');
        $url = "/platform/tenants/{$tenant->id}/users/{$user->id}";
        $data = ['name' => 'Changed', 'username' => 'edit.changed', 'email' => 'changed@edit.local', 'role' => 'Site Engineer'];
        $this->patch($url, $data)->assertSessionHasErrors('role');
        $this->assertSame('admin@edit.local', CentralUser::where('username', 'edit.admin')->firstOrFail()->email);
        $this->patch($url, [...$data, 'role' => 'System Administrator', 'username' => 'EDIT.OTHER'])->assertSessionHasErrors('username');
        $this->patch($url, [...$data, 'role' => 'Platform Admin'])->assertSessionHasErrors('role');
        $this->patch($url, [...$data, 'role' => 'System Administrator', 'password' => 'new-password', 'password_confirmation' => 'wrong'])->assertSessionHasErrors('password');
        $tenant->update(['status' => 'suspended']);
        $this->patch($url, [...$data, 'role' => 'System Administrator'])->assertForbidden();
    }

    public function test_tenant_user_cannot_edit_through_platform_route(): void
    {
        $tenant = $this->provisionTenant('platform-edit-denied');
        $user = app(AuthService::class)->createUser($tenant, ['name' => 'Admin', 'username' => 'edit.denied', 'email' => 'admin@denied.local', 'password' => 'password', 'role' => 'System Administrator']);
        $this->actingAs($user, 'web')->patch("/platform/tenants/{$tenant->id}/users/{$user->id}", [])->assertRedirect('/platform/login');
        tenancy()->end();
    }
}
