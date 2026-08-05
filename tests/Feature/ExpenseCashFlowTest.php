<?php

namespace Tests\Feature;

use App\Enums\BudgetTransactionType;
use App\Enums\ExpenseCategory;
use App\Models\BudgetTransaction;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\PermissionService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCashFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Expense Cash Co',
            'slug' => 'expense-cash-co',
        ]);

        $auth = app(AuthService::class);
        $auth->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@expensecash.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'EX-001',
                'name' => 'Expense Cash Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000000.00',
                'wht_percentage' => '5.00',
                'net_budget' => '9500000.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);

            Project::create([
                'code' => 'EX-002',
                'name' => 'Other Project',
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

        return $tenant;
    }

    public function test_direct_expense_reduces_finance_wallet_and_posts_budget(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '200000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Transport',
            'amount' => '75000',
            'description' => 'Site transport',
            'expense_date' => now()->toDateString(),
            'payee' => 'Driver',
            'reference_no' => 'RCP-EXP-1',
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame('125000.00', app(MoneyAccountService::class)->financeBalance());

            $expense = Expense::first();
            $this->assertSame(ExpenseCategory::Direct, $expense->category);
            $this->assertSame('75000.00', (string) $expense->amount);

            $disbursement = CashDisbursement::first();
            $this->assertSame($expense->id, $disbursement->expense_id);
            $this->assertNull($disbursement->requisition_id);
            $this->assertNull($disbursement->cash_allocation_id);
            $this->assertSame('Driver', $disbursement->payee);

            $budget = BudgetTransaction::where('type', BudgetTransactionType::DirectExpense)->first();
            $this->assertNotNull($budget);
            $this->assertSame('75000.00', (string) $budget->amount);

            $position = app(ReportService::class)->cashPosition([]);
            $this->assertSame('125000.00', $position['cash_on_hand']);
        });
    }

    public function test_cannot_expense_above_finance_wallet_balance(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '50000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Fuel',
            'amount' => '50001',
            'expense_date' => now()->toDateString(),
        ])->assertSessionHasErrors(['amount']);

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame(0, Expense::count());
            $this->assertSame('50000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_indirect_and_direct_expenses_share_finance_wallet(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '200000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Rent',
            'amount' => '50000',
            'expense_date' => now()->toDateString(),
            'method' => 'bank',
        ])->assertRedirect();

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Transport',
            'amount' => '80000',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame(2, Expense::count());
            $this->assertSame('70000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_editing_and_deleting_direct_expense_adjusts_finance_wallet(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '200000.00']);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $payload = [
            'category' => 'direct',
            'project_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Fuel',
            'amount' => '75000',
            'expense_date' => now()->toDateString(),
        ];

        $this->post('/finance/expenses', $payload)->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame('125000.00', app(MoneyAccountService::class)->financeBalance());
        });

        $this->put('/finance/expenses/1', [
            ...$payload,
            'amount' => '50000',
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame('150000.00', app(MoneyAccountService::class)->financeBalance());
            $this->assertSame('50000.00', (string) Expense::first()->amount);
        });

        $this->delete('/finance/expenses/1')->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame(0, Expense::count());
            $this->assertSame('200000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }
}
