<?php

namespace Tests\Feature;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_resolves_tenant_and_authenticates_user(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Test Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $response = $this->post('/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertEquals($tenant->id, session('tenant_id'));
    }

    public function test_invalid_credentials_return_generic_error(): void
    {
        $response = $this->post('/login', [
            'email' => 'unknown@test.local',
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
