<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionType;
use App\Enums\DepositSource;
use App\Enums\ExpenseCategory;
use App\Enums\MoneyAccountType;
use App\Enums\PhaseStatus;
use App\Enums\ProjectStatus;
use App\Enums\SaleStatus;
use App\Enums\ValuationStatus;
use App\Models\AccountTransaction;
use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Valuation;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Support\OrganizationFundUse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeStatementTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'IS Co',
            'slug' => 'is-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@is-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();
        });

        tenancy()->end();

        return $tenant;
    }

    private function login(): void
    {
        $this->post('/login', [
            'email' => 'admin@is-co.local',
            'password' => 'password',
        ]);
    }

    public function test_finance_menu_route_renders_draft_income_statement(): void
    {
        $this->seedTenant();
        $this->login();

        $this->get('/finance/income-statement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/IncomeStatement')
                ->where('mode', 'draft')
                ->has('statement.lines')
                ->has('statement.totals')
                ->has('statement.projects')
            );
    }

    public function test_statement_includes_receivables_direct_categories_ipc_and_excludes_loans(): void
    {
        $tenant = $this->seedTenant();
        $this->login();

        $tenant->run(function () {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'IS-1',
                'name' => 'Bridge',
                'client' => 'Gov',
                'location' => 'Dar',
                'contract_amount' => '100000.00',
                'wht_percentage' => '0',
                'net_budget' => '100000.00',
                'physical_progress_pct' => '50',
                'start_date' => now()->subMonths(3),
                'end_date' => now()->addMonths(3),
                'status' => ProjectStatus::Active,
            ]);

            $phase = ProjectPhase::create([
                'project_id' => $project->id,
                'sequence_no' => 1,
                'name' => 'Phase 1',
                'status' => PhaseStatus::Closed,
                'disbursed_amount' => '100000.00',
                'phase_net_budget' => '100000.00',
            ]);

            $valuation = Valuation::create([
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'certificate_no' => 1,
                'gross_value' => '100000.00',
                'total_deductions' => '800.00',
                'net_value' => '99200.00',
                'status' => ValuationStatus::Certified,
                'created_by' => $userId,
                'certified_by' => $userId,
                'certified_at' => now()->subDay(),
            ]);

            Sale::create([
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'sale_code' => 'SAL-001',
                'status' => SaleStatus::Receivable,
                'contract_amount' => '100000.00',
                'profit_amount' => '10000.00',
                'collected_amount' => '0.00',
                'converted_at' => now()->subDay(),
                'converted_by' => $userId,
            ]);

            Sale::create([
                'project_id' => $project->id,
                'phase_id' => null,
                'sale_code' => 'RET-001',
                'status' => SaleStatus::Receivable,
                'contract_amount' => '100000.00',
                'profit_amount' => '2500.00',
                'collected_amount' => '0.00',
                'converted_at' => now()->subDay(),
                'converted_by' => $userId,
            ]);

            Expense::create([
                'project_id' => $project->id,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Casual Labour',
                'amount' => '1500.00',
                'description' => 'Site labour',
                'expense_date' => now()->subDay()->toDateString(),
                'recorded_by' => $userId,
            ]);

            Expense::create([
                'project_id' => $project->id,
                'valuation_id' => $valuation->id,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'WHT',
                'amount' => '800.00',
                'description' => 'IPC WHT',
                'expense_date' => now()->subDay()->toDateString(),
                'recorded_by' => $userId,
            ]);

            Expense::create([
                'category' => ExpenseCategory::Indirect,
                'sub_type' => OrganizationFundUse::SALARIES,
                'amount' => '3000.00',
                'description' => 'Payroll',
                'expense_date' => now()->subDay()->toDateString(),
                'recorded_by' => $userId,
            ]);

            $account = MoneyAccount::create([
                'type' => MoneyAccountType::Manager,
                'name' => 'Company',
                'balance' => '50000.00',
                'is_active' => true,
                'created_by' => $userId,
            ]);

            AccountTransaction::create([
                'money_account_id' => $account->id,
                'type' => AccountTransactionType::Deposit,
                'deposit_source' => DepositSource::OtherIncome,
                'amount' => '400.00',
                'balance_after' => '50400.00',
                'description' => 'Misc income',
                'recorded_by' => $userId,
                'occurred_at' => now()->subDay(),
            ]);

            AccountTransaction::create([
                'money_account_id' => $account->id,
                'type' => AccountTransactionType::Deposit,
                'deposit_source' => DepositSource::Loan,
                'amount' => '20000.00',
                'balance_after' => '70400.00',
                'description' => 'Bank loan',
                'recorded_by' => $userId,
                'occurred_at' => now()->subDay(),
            ]);
        });

        $response = $this->get('/finance/income-statement?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString())
            ->assertOk();

        $response->assertInertia(function ($page) {
            $page->component('Finance/IncomeStatement')
                ->where('mode', 'draft');

            $props = $page->toArray()['props'];
            $totals = $props['statement']['totals'];
            $lines = collect($props['statement']['lines']);

            $this->assertSame('12900.00', $totals['total_revenue']); // 10000 + 2500 + 400
            $this->assertSame('2300.00', $totals['total_direct']); // 1500 + 800
            $this->assertSame('3000.00', $totals['total_indirect']);
            $this->assertSame('5300.00', $totals['total_expenses']);
            $this->assertSame('7600.00', $totals['ebitda']);
            $this->assertSame('7600.00', $totals['net_profit']);

            $this->assertTrue($lines->contains(fn ($l) => $l['key'] === 'project_receivables' && $l['amount'] === '10000.00'));
            $this->assertTrue($lines->contains(fn ($l) => $l['key'] === 'retention_receivables' && $l['amount'] === '2500.00'));
            $this->assertTrue($lines->contains(fn ($l) => $l['key'] === 'other_income' && $l['amount'] === '400.00'));
            $this->assertTrue($lines->contains(fn ($l) => $l['key'] === 'casual_labour' && $l['amount'] === '1500.00'));
            $this->assertTrue($lines->contains(fn ($l) => $l['label'] === 'WHT' && $l['amount'] === '800.00'));
            $this->assertTrue($lines->contains(fn ($l) => $l['key'] === 'salaries' && $l['amount'] === '3000.00'));
            $this->assertFalse($lines->contains(fn ($l) => str_contains(mb_strtolower($l['label']), 'loan')));
        });
    }

    public function test_finalize_applies_interest_tax_and_adhoc_adjustments(): void
    {
        $tenant = $this->seedTenant();
        $this->login();

        $tenant->run(function () {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'IS-2',
                'name' => 'Road',
                'client' => 'Gov',
                'location' => 'Arusha',
                'contract_amount' => '50000.00',
                'wht_percentage' => '0',
                'net_budget' => '50000.00',
                'physical_progress_pct' => '20',
                'start_date' => now()->subMonth(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = ProjectPhase::create([
                'project_id' => $project->id,
                'sequence_no' => 1,
                'name' => 'Phase 1',
                'status' => PhaseStatus::Closed,
                'disbursed_amount' => '50000.00',
                'phase_net_budget' => '50000.00',
            ]);

            Sale::create([
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'sale_code' => 'SAL-100',
                'status' => SaleStatus::Paid,
                'contract_amount' => '50000.00',
                'profit_amount' => '5000.00',
                'collected_amount' => '5000.00',
                'converted_at' => now(),
                'converted_by' => $userId,
            ]);
        });

        $this->post('/finance/income-statement/finalize', [
            'from' => now()->subWeek()->toDateString(),
            'to' => now()->toDateString(),
            'memo_no' => 'MEM-1',
            'interest' => '100',
            'interest_mode' => 'fixed',
            'depreciation' => '50',
            'depreciation_mode' => 'fixed',
            'corporate_tax' => '200',
            'corporate_tax_mode' => 'fixed',
            'adhoc' => [
                [
                    'label' => 'Bank charges',
                    'value' => '25',
                    'mode' => 'fixed',
                    'section' => 'below_ebitda',
                ],
            ],
        ])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/IncomeStatement')
                ->where('mode', 'final')
                ->where('statement.memo_no', 'MEM-1')
                ->where('statement.totals.total_revenue', '5000.00')
                ->where('statement.totals.ebitda', '5000.00')
                // 5000 - 100 - 50 - 25 - 200 = 4625
                ->where('statement.totals.net_profit', '4625.00')
                ->where('statement.adjustments.interest', '100.00')
            );
    }

    public function test_percent_adjustments_resolve_amounts_from_bases(): void
    {
        $tenant = $this->seedTenant();
        $this->login();

        $tenant->run(function () {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'IS-3',
                'name' => 'Dam',
                'client' => 'Gov',
                'location' => 'Mwanza',
                'contract_amount' => '50000.00',
                'wht_percentage' => '0',
                'net_budget' => '50000.00',
                'physical_progress_pct' => '20',
                'start_date' => now()->subMonth(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = ProjectPhase::create([
                'project_id' => $project->id,
                'sequence_no' => 1,
                'name' => 'Phase 1',
                'status' => PhaseStatus::Closed,
                'disbursed_amount' => '50000.00',
                'phase_net_budget' => '50000.00',
            ]);

            Sale::create([
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'sale_code' => 'SAL-200',
                'status' => SaleStatus::Receivable,
                'contract_amount' => '50000.00',
                'profit_amount' => '10000.00',
                'collected_amount' => '0.00',
                'converted_at' => now(),
                'converted_by' => $userId,
            ]);
        });

        // EBITDA 10000; interest 10% = 1000; depr 5% = 500; PBT = 8500; tax 30% = 2550; net = 5950
        $this->post('/finance/income-statement/finalize', [
            'from' => now()->subWeek()->toDateString(),
            'to' => now()->toDateString(),
            'interest' => '10',
            'interest_mode' => 'percent',
            'depreciation' => '5',
            'depreciation_mode' => 'percent',
            'corporate_tax' => '30',
            'corporate_tax_mode' => 'percent',
        ])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/IncomeStatement')
                ->where('mode', 'final')
                ->where('statement.totals.ebitda', '10000.00')
                ->where('statement.totals.interest', '1000.00')
                ->where('statement.totals.depreciation', '500.00')
                ->where('statement.totals.corporate_tax', '2550.00')
                ->where('statement.totals.net_profit', '5950.00')
            );
    }

    public function test_export_csv_streams_final_statement(): void
    {
        $this->seedTenant();
        $this->login();

        $this->post('/finance/income-statement/export', [
            'format' => 'csv',
            'interest' => '0',
            'interest_mode' => 'fixed',
            'depreciation' => '0',
            'depreciation_mode' => 'fixed',
            'corporate_tax' => '0',
            'corporate_tax_mode' => 'fixed',
        ])
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
