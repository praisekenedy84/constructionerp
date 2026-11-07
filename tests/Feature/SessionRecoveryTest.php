<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_use_public_application_assets_and_are_not_cached(): void
    {
        $tenant = Tenant::create(['name' => 'Recovery', 'slug' => 'session-recovery']);
        app(AuthService::class)->createUser($tenant, ['name' => 'Admin', 'username' => 'recovery.admin', 'email' => 'recovery@example.com', 'password' => 'password', 'role' => 'System Administrator']);
        tenancy()->end();
        $this->post('/login', ['username' => 'recovery.admin', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('/build/assets/', false)->assertDontSee('/tenancy/assets/build', false)
            ->assertSee('app-bootstrap-recovery')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_old_or_malformed_session_is_replaced_with_a_login_redirect(): void
    {
        $this->withSession(['home_tenant_id' => 'legacy', 'tenant_id' => 'legacy', 'central_identity_id' => 99,
            'verified_company_accounts' => 'old-format', 'auth_context_version' => 1, 'current_project_id' => 10])
            ->get('/dashboard')->assertRedirect('/login')->assertSessionMissing('home_tenant_id')
            ->assertSessionMissing('verified_company_accounts')->assertSessionMissing('current_project_id');
        $this->get('/login')->assertOk();
    }

    public function test_session_reset_does_not_require_resolving_a_broken_tenant(): void
    {
        $this->withSession(['home_tenant_id' => 'missing', 'tenant_id' => 'missing', 'central_identity_id' => 99,
            'verified_company_accounts' => 'invalid', 'auth_context_version' => 2])
            ->post('/auth/reset-session')->assertRedirect('/login')->assertSessionMissing('tenant_id');
        $this->get('/login')->assertOk();
    }

    public function test_stale_inertia_version_forces_full_navigation_and_preserves_login(): void
    {
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'old-release'])
            ->get('/login')->assertStatus(409)->assertHeader('X-Inertia-Location');
    }

    public function test_deploy_version_and_manifest_both_contribute_to_asset_version(): void
    {
        config(['app.asset_version' => 'release-one']);
        $middleware = app(HandleInertiaRequests::class);
        $request = Request::create('/login');
        $manifest = public_path('build/manifest.json');
        $this->assertSame(hash('sha256', 'release-one|'.hash_file('sha256', $manifest)), $middleware->version($request));
        $first = $middleware->version($request);
        config(['app.asset_version' => 'release-two']);
        $this->assertNotSame($first, $middleware->version($request));
    }

    public function test_expired_csrf_and_stale_account_responses_use_browser_navigation(): void
    {
        Route::middleware('web')->post('/_test/expired-session', fn () => abort(419));
        Route::middleware('web')->post('/_test/stale-account', fn () => abort(409));
        $this->withHeader('X-Inertia', 'true')->post('/_test/expired-session')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
        $this->post('/_test/stale-account')->assertStatus(409)->assertHeader('X-Inertia-Location', route('dashboard'));
    }
}
