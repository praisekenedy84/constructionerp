<?php

namespace Tests\Feature;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CompanyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_selection_preserves_home_identity_and_blocks_writes(): void
    {
        $home = Tenant::create(['name' => 'Home', 'slug' => 'access-home']);
        $user = app(AuthService::class)->createUser($home, ['name' => 'Home admin', 'email' => 'home@access.local', 'password' => 'password', 'role' => 'System Administrator']);
        $homeId = $user->id;
        tenancy()->end();
        $target = Tenant::create(['name' => 'Target', 'slug' => 'access-target']);
        app(AuthService::class)->createUser($target, ['name' => 'Other user', 'email' => 'target@access.local', 'password' => 'password', 'role' => 'System Administrator']);
        tenancy()->end();
        $identity = CentralUser::where('email', 'home@access.local')->firstOrFail();
        app(CompanyAccessService::class)->central()->table('company_access_grants')->insert(['central_user_id' => $identity->id, 'tenant_id' => $target->id, 'granted_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/login', ['email' => $identity->email, 'password' => 'password'])->assertRedirect('/auth/companies');
        $this->post('/auth/company', ['tenant_id' => $target->id])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/VisitorDashboard')->where('auth.user.email', 'home@access.local')->where('companyContext.read_only', true)->has('uiSettings.app_name')->has('navigation'));
        $this->assertEquals('home@access.local', auth()->user()->email);
        $this->assertEquals($homeId, auth()->id());
        $this->withHeader('X-Company-Context', $target->id)->post('/projects', [])->assertForbidden();
        $this->get('/projects/create')->assertForbidden();
        $this->get('/reports/export/executive_dashboard')->assertForbidden();
        $this->withHeader('X-Company-Context', 'wrong-company')->post('/projects', [])->assertStatus(409);
        app(CompanyAccessService::class)->central()->table('company_access_grants')->where('central_user_id', $identity->id)->delete();
        $this->get('/projects')->assertRedirect('/auth/companies');
        $this->post('/auth/company', ['tenant_id' => $target->id])->assertForbidden();
        $this->post('/auth/company', ['tenant_id' => $home->id])->assertRedirect('/dashboard');
        $this->assertEquals($home->id, session('tenant_id'));
    }

    public function test_visitor_permissions_cannot_use_administrator_bypasses(): void
    {
        session(['home_tenant_id' => 'home', 'tenant_id' => 'other']);
        request()->attributes->set('home_permissions', ['projects:read', 'projects:create', 'reports:export']);
        $user = new User;
        $this->assertTrue($user->hasModulePermission('projects', 'read'));
        $this->assertFalse($user->hasModulePermission('projects', 'create'));
        $this->assertFalse($user->hasModulePermission('payroll', 'read'));
        $this->assertFalse($user->hasModulePermission('reports', 'export'));
        $this->assertSame(['projects:read'], $user->modulePermissions());
        $this->assertFalse($user->isSuperUser());
    }

    public function test_attachment_protection_command_is_repeatable_for_empty_tenants(): void
    {
        Tenant::create(['name' => 'Files', 'slug' => 'access-files']);
        $this->artisan('company-access:protect-attachments')->assertSuccessful();
        $this->artisan('company-access:protect-attachments')->assertSuccessful();
        $this->assertSame(2, app(CompanyAccessService::class)->central()->table('company_access_events')->where('action', 'attachments_protected')->count());
    }
}
