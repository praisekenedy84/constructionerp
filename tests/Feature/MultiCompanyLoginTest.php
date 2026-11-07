<?php

namespace Tests\Feature;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CompanyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiCompanyLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_login_offers_verified_company_accounts_with_their_own_roles(): void
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'multi-login-a']);
        app(AuthService::class)->createUser($a, ['name' => 'Director', 'username' => 'multi.shared', 'email' => 'shared@multi.local', 'password' => 'shared-password', 'role' => 'System Administrator']);
        tenancy()->end();
        $b = Tenant::create(['name' => 'B', 'slug' => 'multi-login-b']);
        $account = app(AuthService::class)->createUser($b, ['name' => 'Finance', 'username' => 'multi.shared', 'email' => 'shared@multi.local', 'password' => 'shared-password', 'role' => 'Finance Manager']);
        app(AuthService::class)->createUser($b, ['name' => 'Other', 'username' => 'multi.other', 'email' => 'shared@multi.local', 'password' => 'different-password', 'role' => 'System Administrator']);
        tenancy()->end();
        $identity = CentralUser::where('tenant_id', $b->id)->where('username', 'multi.shared')->firstOrFail();
        $excluded = CentralUser::where('username', 'multi.other')->firstOrFail();
        $this->post('/login', ['username' => 'SHARED@MULTI.LOCAL', 'password' => 'shared-password'])->assertRedirect('/auth/companies');
        $choices = app(CompanyAccessService::class)->companies();
        $this->assertCount(2, $choices);
        $this->assertSame([false, false], array_column($choices, 'read_only'));
        $this->post('/auth/company', ['tenant_id' => $b->id, 'identity_id' => $excluded->id])->assertForbidden();
        $this->post('/auth/company', ['tenant_id' => $b->id, 'identity_id' => $identity->id])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->assertSame('multi.shared', auth()->user()->username);
        $this->assertTrue(auth()->user()->hasRole('Finance Manager'));
        $this->assertFalse(CompanyAccessService::visiting());
        $this->withHeaders(['X-Company-Context' => $b->id, 'X-Account-Context' => (string) $identity->id])
            ->post('/auth/impersonate/1')->assertForbidden();
        $this->withHeaders(['X-Company-Context' => $b->id, 'X-Account-Context' => 'wrong'])->post('/projects', [])->assertStatus(409);
        $aIdentity = CentralUser::where('tenant_id', $a->id)->where('username', 'multi.shared')->firstOrFail();
        $this->post('/auth/company', ['tenant_id' => $a->id, 'identity_id' => $aIdentity->id])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->assertTrue(auth()->user()->hasRole('System Administrator'));
        $b->run(fn () => User::withoutEvents(fn () => User::findOrFail($account->id)->update(['password' => Hash::make('changed-password')])));
        $this->post('/auth/company', ['tenant_id' => $b->id, 'identity_id' => $identity->id])->assertForbidden();
    }

    public function test_username_login_can_unlock_matching_accounts_but_wrong_password_does_not(): void
    {
        $tenant = Tenant::create(['name' => 'Same company', 'slug' => 'multi-login-same']);
        app(AuthService::class)->createUser($tenant, ['name' => 'One', 'username' => 'same.one', 'email' => 'same@multi.local', 'password' => 'matching-password', 'role' => 'Site Engineer']);
        app(AuthService::class)->createUser($tenant, ['name' => 'Two', 'username' => 'same.two', 'email' => 'same@multi.local', 'password' => 'matching-password', 'role' => 'Finance Manager']);
        tenancy()->end();
        $this->post('/login', ['username' => 'same.one', 'password' => 'wrong-password'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->post('/login', ['username' => 'same.one', 'password' => 'matching-password'])->assertRedirect('/auth/companies');
        $this->assertCount(2, app(CompanyAccessService::class)->companies());
        $identity = CentralUser::where('username', 'same.two')->firstOrFail();
        $this->post('/auth/company', ['tenant_id' => $tenant->id, 'identity_id' => $identity->id])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->assertSame('same.two', auth()->user()->username);
        $this->assertTrue(auth()->user()->hasRole('Finance Manager'));
    }
}
