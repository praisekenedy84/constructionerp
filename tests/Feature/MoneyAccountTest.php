<?php

namespace Tests\Feature;

use App\Enums\MoneyAccountType;
use App\Models\MoneyAccount;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAccountTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Accounts Co',
            'slug' => 'accounts-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@accounts.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();
        });

        tenancy()->end();
    }

    public function test_creating_company_account_requires_account_and_bank_name(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@accounts.local',
            'password' => 'password',
        ]);

        $this->post('/finance/accounts', [
            'name' => 'Operating Account',
            'notes' => 'Main operating funds',
        ])->assertSessionHasErrors(['bank_name']);

        $this->post('/finance/accounts', [
            'name' => 'Operating Account',
            'bank_name' => 'CRDB Bank',
            'notes' => 'Main operating funds',
        ])->assertRedirect();

        Tenant::where('slug', 'accounts-co')->first()->run(function () {
            $account = MoneyAccount::query()
                ->where('type', MoneyAccountType::Manager)
                ->where('name', 'Operating Account')
                ->first();

            $this->assertNotNull($account);
            $this->assertSame('CRDB Bank', $account->bank_name);
            $this->assertSame('Main operating funds', $account->notes);
        });
    }

    public function test_accounts_index_includes_bank_name(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@accounts.local',
            'password' => 'password',
        ]);

        $this->post('/finance/accounts', [
            'name' => 'Petty Cash',
            'bank_name' => 'NMB Bank',
        ])->assertRedirect();

        $this->get('/finance/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Accounts')
                ->has('accounts')
                ->where('accounts', function ($accounts) {
                    return collect($accounts)->contains(function ($account) {
                        return ($account['name'] ?? null) === 'Petty Cash'
                            && ($account['bank_name'] ?? null) === 'NMB Bank';
                    });
                })
            );
    }
}
