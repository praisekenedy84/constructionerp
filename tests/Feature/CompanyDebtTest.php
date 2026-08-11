<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionType;
use App\Enums\CompanyDebtStatus;
use App\Enums\CompanyDebtType;
use App\Enums\DepositSource;
use App\Enums\MoneyAccountType;
use App\Models\AccountTransaction;
use App\Models\CompanyDebt;
use App\Models\MoneyAccount;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDebtTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Debts Co',
            'slug' => 'debts-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@debts.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();
        });

        tenancy()->end();
    }

    private function login(): void
    {
        $this->post('/login', [
            'email' => 'admin@debts.local',
            'password' => 'password',
        ]);
    }

    private function createCompanyAccount(): int
    {
        $this->post('/finance/accounts', [
            'name' => 'Operating Account',
            'bank_name' => 'CRDB Bank',
        ])->assertRedirect();

        $accountId = null;
        Tenant::where('slug', 'debts-co')->first()->run(function () use (&$accountId) {
            $accountId = MoneyAccount::query()
                ->where('type', MoneyAccountType::Manager)
                ->where('name', 'Operating Account')
                ->value('id');
        });

        return (int) $accountId;
    }

    public function test_deposit_requires_source(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '100000',
            'description' => 'Missing source',
        ])->assertSessionHasErrors(['deposit_source']);
    }

    public function test_owner_capital_deposit_does_not_create_debt(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '250000',
            'deposit_source' => DepositSource::OwnerCapital->value,
            'description' => 'Owner top-up',
        ])->assertRedirect();

        Tenant::where('slug', 'debts-co')->first()->run(function () use ($accountId) {
            $tx = AccountTransaction::query()
                ->where('money_account_id', $accountId)
                ->where('type', AccountTransactionType::Deposit)
                ->latest('id')
                ->first();

            $this->assertNotNull($tx);
            $this->assertSame(DepositSource::OwnerCapital, $tx->deposit_source);
            $this->assertSame(0, CompanyDebt::query()->count());

            $account = MoneyAccount::findOrFail($accountId);
            $this->assertSame('250000.00', (string) $account->balance);
        });
    }

    public function test_loan_deposit_creates_open_debt(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '500000',
            'deposit_source' => DepositSource::Loan->value,
            'creditor_name' => 'NMB Bank',
            'description' => 'Working capital loan',
        ])->assertRedirect();

        Tenant::where('slug', 'debts-co')->first()->run(function () use ($accountId) {
            $debt = CompanyDebt::query()->first();
            $this->assertNotNull($debt);
            $this->assertSame(CompanyDebtType::Loan, $debt->type);
            $this->assertSame(CompanyDebtStatus::Open, $debt->status);
            $this->assertSame('NMB Bank', $debt->creditor_name);
            $this->assertSame('500000.00', (string) $debt->original_amount);
            $this->assertSame('500000.00', (string) $debt->outstanding_amount);
            $this->assertSame($accountId, $debt->money_account_id);

            $tx = AccountTransaction::findOrFail($debt->deposit_transaction_id);
            $this->assertSame(DepositSource::Loan, $tx->deposit_source);
            $this->assertSame('company_debt', $tx->reference_entity_type);
            $this->assertSame($debt->id, $tx->reference_entity_id);
        });
    }

    public function test_loan_deposit_requires_creditor_name(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '100000',
            'deposit_source' => DepositSource::Loan->value,
        ])->assertSessionHasErrors(['creditor_name']);
    }

    public function test_customer_advance_deposit_creates_debt(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '150000',
            'deposit_source' => DepositSource::CustomerAdvance->value,
            'creditor_name' => 'Acme Client',
        ])->assertRedirect();

        Tenant::where('slug', 'debts-co')->first()->run(function () {
            $debt = CompanyDebt::query()->first();
            $this->assertNotNull($debt);
            $this->assertSame(CompanyDebtType::CustomerAdvance, $debt->type);
            $this->assertSame('Acme Client', $debt->creditor_name);
        });
    }

    public function test_partial_and_full_repayment_clears_debt(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '400000',
            'deposit_source' => DepositSource::Loan->value,
            'creditor_name' => 'Lender Co',
        ])->assertRedirect();

        $debtId = null;
        Tenant::where('slug', 'debts-co')->first()->run(function () use (&$debtId) {
            $debtId = CompanyDebt::query()->value('id');
        });

        $this->post("/finance/debts/{$debtId}/payments", [
            'amount' => '150000',
            'money_account_id' => $accountId,
            'method' => 'bank',
            'reference_no' => 'REP-1',
        ])->assertRedirect();

        Tenant::where('slug', 'debts-co')->first()->run(function () use ($debtId, $accountId) {
            $debt = CompanyDebt::findOrFail($debtId);
            $this->assertSame(CompanyDebtStatus::PartiallyPaid, $debt->status);
            $this->assertSame('250000.00', (string) $debt->outstanding_amount);
            $this->assertSame(1, $debt->payments()->count());

            $repayTx = AccountTransaction::query()
                ->where('type', AccountTransactionType::DebtRepayment)
                ->first();
            $this->assertNotNull($repayTx);
            $this->assertSame('150000.00', (string) $repayTx->amount);
            $this->assertFalse($repayTx->isCredit());

            $account = MoneyAccount::findOrFail($accountId);
            $this->assertSame('250000.00', (string) $account->balance);
        });

        $this->post("/finance/debts/{$debtId}/payments", [
            'amount' => '250000',
            'money_account_id' => $accountId,
        ])->assertRedirect();

        Tenant::where('slug', 'debts-co')->first()->run(function () use ($debtId, $accountId) {
            $debt = CompanyDebt::findOrFail($debtId);
            $this->assertSame(CompanyDebtStatus::Cleared, $debt->status);
            $this->assertSame('0.00', (string) $debt->outstanding_amount);
            $this->assertSame(2, $debt->payments()->count());

            $account = MoneyAccount::findOrFail($accountId);
            $this->assertSame('0.00', (string) $account->balance);
        });
    }

    public function test_over_repayment_is_rejected(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '100000',
            'deposit_source' => DepositSource::Loan->value,
            'creditor_name' => 'Lender Co',
        ])->assertRedirect();

        $debtId = null;
        Tenant::where('slug', 'debts-co')->first()->run(function () use (&$debtId) {
            $debtId = CompanyDebt::query()->value('id');
        });

        $this->from("/finance/debts/{$debtId}")
            ->post("/finance/debts/{$debtId}/payments", [
                'amount' => '150000',
                'money_account_id' => $accountId,
            ])
            ->assertRedirect("/finance/debts/{$debtId}")
            ->assertSessionHas('error');

        Tenant::where('slug', 'debts-co')->first()->run(function () use ($debtId) {
            $debt = CompanyDebt::findOrFail($debtId);
            $this->assertSame(CompanyDebtStatus::Open, $debt->status);
            $this->assertSame('100000.00', (string) $debt->outstanding_amount);
            $this->assertSame(0, $debt->payments()->count());
        });
    }

    public function test_cannot_repay_cleared_debt(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '80000',
            'deposit_source' => DepositSource::Loan->value,
            'creditor_name' => 'Lender Co',
        ])->assertRedirect();

        // Extra funds so a second attempt could otherwise succeed on balance alone.
        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '50000',
            'deposit_source' => DepositSource::OwnerCapital->value,
        ])->assertRedirect();

        $debtId = null;
        Tenant::where('slug', 'debts-co')->first()->run(function () use (&$debtId) {
            $debtId = CompanyDebt::query()->value('id');
        });

        $this->post("/finance/debts/{$debtId}/payments", [
            'amount' => '80000',
            'money_account_id' => $accountId,
        ])->assertRedirect();

        $this->from("/finance/debts/{$debtId}")
            ->post("/finance/debts/{$debtId}/payments", [
                'amount' => '10000',
                'money_account_id' => $accountId,
            ])
            ->assertRedirect("/finance/debts/{$debtId}")
            ->assertSessionHas('error');
    }

    public function test_debts_index_and_show_render(): void
    {
        $this->seedTenant();
        $this->login();
        $accountId = $this->createCompanyAccount();

        $this->post("/finance/accounts/{$accountId}/deposit", [
            'amount' => '200000',
            'deposit_source' => DepositSource::Loan->value,
            'creditor_name' => 'Bank One',
        ])->assertRedirect();

        $debtId = null;
        Tenant::where('slug', 'debts-co')->first()->run(function () use (&$debtId) {
            $debtId = CompanyDebt::query()->value('id');
        });

        $this->get('/finance/debts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Debts/Index')
                ->has('debts.data', 1)
                ->where('summary.open_count', 1)
            );

        $this->get("/finance/debts/{$debtId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Debts/Show')
                ->where('debt.creditor_name', 'Bank One')
                ->where('debt.status', 'open')
                ->has('manager_accounts')
            );
    }

    public function test_accounts_index_includes_deposit_sources(): void
    {
        $this->seedTenant();
        $this->login();

        $this->get('/finance/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Accounts')
                ->has('deposit_sources')
                ->where('deposit_sources.0.value', DepositSource::OwnerCapital->value)
            );
    }
}
