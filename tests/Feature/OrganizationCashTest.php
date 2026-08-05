<?php

namespace Tests\Feature;

use App\Enums\CashAllocationStatus;
use App\Enums\MoneyAccountType;
use App\Models\CashAllocation;
use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationCashTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Org Cash Co',
            'slug' => 'org-cash-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@orgcash.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'ORG-P1',
                'name' => 'Site One',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '5000000.00',
                'wht_percentage' => '5.00',
                'net_budget' => '4750000.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);
        });

        tenancy()->end();
    }

    public function test_organization_cash_redirects_to_finance_transactions(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '500000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->get('/finance/organization-cash')
            ->assertRedirect('/finance/finance-transactions');

        $this->get('/finance/finance-transactions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/FinanceTransactions')
                ->where('summary.balance', '500000.00')
            );
    }

    public function test_approving_fund_transfers_into_finance_wallet(): void
    {
        $this->seedTenant();

        $accountId = null;

        $this->post('/login', [
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        Tenant::where('slug', 'org-cash-co')->first()->run(function () use (&$accountId) {
            $accountId = (int) MoneyAccount::query()
                ->where('type', MoneyAccountType::Manager)
                ->orderBy('id')
                ->value('id');

            app(MoneyAccountService::class)->deposit(
                MoneyAccount::findOrFail($accountId),
                '250000',
                User::first(),
            );
        });

        $this->post('/finance/cash-requests', [
            'requested_amount' => '250000',
        ])->assertRedirect();

        $this->post('/finance/cash-requests/1/approve', [
            'source_account_id' => $accountId,
            'approved_amount' => '250000',
            'method' => 'bank',
            'reference_no' => 'ORG-REQ-1',
        ])->assertRedirect();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $allocation = CashAllocation::first();
            $this->assertNull($allocation->project_id);
            $this->assertSame(CashAllocationStatus::Received, $allocation->status);
            $this->assertSame('250000.00', (string) $allocation->received_amount);
            $this->assertSame('250000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_overhead_cannot_exceed_finance_wallet(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '100000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Rent',
            'amount' => '100001',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertSessionHasErrors(['amount']);

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $this->assertSame(0, Expense::count());
            $this->assertSame('100000.00', app(MoneyAccountService::class)->financeBalance());
        });

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Rent',
            'amount' => '100000',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertRedirect();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $this->assertSame(1, Expense::count());
            $this->assertSame('0.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_project_and_company_expenses_share_finance_wallet(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '150000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Utilities',
            'amount' => '50000',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertRedirect();

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'sub_type' => 'Transport',
            'amount' => '80000',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertRedirect();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $this->assertSame('20000.00', app(MoneyAccountService::class)->financeBalance());
            $this->assertSame(2, Expense::count());
        });
    }
}
