<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionType;
use App\Enums\BudgetTransactionType;
use App\Enums\MoneyAccountType;
use App\Enums\ProjectStatus;
use App\Enums\SaleStatus;
use App\Models\AccountTransaction;
use App\Models\BudgetTransaction;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\PermissionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleReceivableTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(string $slug = 'sales-co'): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Sales Co',
            'slug' => $slug,
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => "admin@{$slug}.local",
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();
        });

        tenancy()->end();

        return $tenant;
    }

    /**
     * @return array{project: Project, sale: Sale, userId: int}
     */
    private function createClosedProjectWithProfit(Tenant $tenant, string $profit = '600.00'): array
    {
        $result = [];

        $tenant->run(function () use (&$result, $profit) {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'SALE-P1',
                'name' => 'Highway Extension',
                'client' => 'Ministry of Works',
                'location' => 'Dar',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '100',
                'start_date' => now()->subYear(),
                'end_date' => now(),
                'status' => ProjectStatus::Closed,
            ]);

            $spend = bcsub('1000.00', $profit, 2);
            if (bccomp($spend, '0', 2) === 1) {
                BudgetTransaction::create([
                    'project_id' => $project->id,
                    'type' => BudgetTransactionType::DirectExpense,
                    'amount' => $spend,
                    'reason' => 'Site spend',
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }

            $sale = app(SaleService::class)->ensureForProject($project);

            $result = [
                'project' => $project,
                'sale' => $sale,
                'userId' => $userId,
            ];
        });

        return $result;
    }

    public function test_sales_index_lists_projects_as_sales_with_stable_ids(): void
    {
        $tenant = $this->seedTenant('sales-list');

        $tenant->run(function () {
            $project = Project::create([
                'code' => 'PRJ-A',
                'name' => 'Bridge',
                'client' => 'City Council',
                'location' => 'Arusha',
                'contract_amount' => '5000.00',
                'wht_percentage' => '0',
                'net_budget' => '5000.00',
                'physical_progress_pct' => '10',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            app(SaleService::class)->ensureForProject($project);
        });

        $this->post('/login', [
            'email' => 'admin@sales-list.local',
            'password' => 'password',
        ]);

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->has('sales.data', 1)
                ->where('sales.data.0.customer', 'City Council')
                ->where('sales.data.0.contract_amount', '5000.00')
                ->where('sales.data.0.sale_code', fn ($code) => str_starts_with((string) $code, 'SALE-'))
            );

        $saleCode = null;
        $tenant->run(function () use (&$saleCode) {
            $saleCode = Sale::query()->value('sale_code');
        });

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sales.data.0.sale_code', $saleCode)
            );
    }

    public function test_archived_projects_are_excluded_from_the_sales_register(): void
    {
        $tenant = $this->seedTenant('sales-archived');

        $tenant->run(function () {
            $archived = Project::create([
                'code' => 'ARCH-1',
                'name' => 'Archived Job',
                'client' => 'Old Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            app(SaleService::class)->ensureForProject($archived);
            $archived->delete();
        });

        $this->post('/login', [
            'email' => 'admin@sales-archived.local',
            'password' => 'password',
        ]);

        $this->get('/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->has('sales.data', 0)
            );
    }

    public function test_sales_menu_is_visible_for_authorized_users(): void
    {
        $this->seedTenant('sales-menu');

        $this->post('/login', [
            'email' => 'admin@sales-menu.local',
            'password' => 'password',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation', fn ($nav) => collect($nav)->contains('href', '/sales'))
            );
    }

    public function test_open_project_cannot_convert_and_closed_project_can(): void
    {
        $tenant = $this->seedTenant('sales-convert');

        $openSaleId = null;
        $closedSaleId = null;

        $tenant->run(function () use (&$openSaleId, &$closedSaleId) {
            $open = Project::create([
                'code' => 'OPEN-1',
                'name' => 'Open Job',
                'client' => 'Client A',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '50',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);
            $openSaleId = app(SaleService::class)->ensureForProject($open)->id;
        });

        $closed = $this->createClosedProjectWithProfit($tenant, '600.00');
        $closedSaleId = $closed['sale']->id;

        $this->post('/login', [
            'email' => 'admin@sales-convert.local',
            'password' => 'password',
        ]);

        $this->from("/sales/{$openSaleId}")
            ->post("/sales/{$openSaleId}/convert-receivable")
            ->assertRedirect("/sales/{$openSaleId}")
            ->assertSessionHasErrors('convert');

        $this->from("/sales/{$closedSaleId}")
            ->post("/sales/{$closedSaleId}/convert-receivable")
            ->assertRedirect("/sales/{$closedSaleId}")
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($closedSaleId) {
            $sale = Sale::findOrFail($closedSaleId);
            $this->assertSame(SaleStatus::Receivable, $sale->status);
            $this->assertSame('600.00', (string) $sale->profit_amount);
            $this->assertSame('0.00', (string) $sale->collected_amount);
        });

        $this->from("/sales/{$closedSaleId}")
            ->post("/sales/{$closedSaleId}/convert-receivable")
            ->assertRedirect("/sales/{$closedSaleId}")
            ->assertSessionHasErrors('convert');
    }

    public function test_partial_and_full_collections_update_account_and_outstanding(): void
    {
        $tenant = $this->seedTenant('sales-collect');
        $closed = $this->createClosedProjectWithProfit($tenant, '600.00');
        $saleId = $closed['sale']->id;
        $accountId = null;

        $this->post('/login', [
            'email' => 'admin@sales-collect.local',
            'password' => 'password',
        ]);

        $tenant->run(function () use (&$accountId, $saleId) {
            $accountId = (int) MoneyAccount::query()
                ->where('type', MoneyAccountType::Manager)
                ->orderBy('id')
                ->value('id');

            if (! $accountId) {
                $accountId = app(MoneyAccountService::class)
                    ->createManagerAccount('Company Bank', User::query()->first())
                    ->id;
            }

            app(SaleService::class)->convertToReceivable(
                Sale::findOrFail($saleId),
                User::query()->first(),
            );
        });

        $this->from("/sales/{$saleId}")
            ->post("/sales/{$saleId}/collect", [
                'amount' => '200.00',
                'money_account_id' => $accountId,
                'method' => 'bank',
                'reference_no' => 'RCV-1',
            ])
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($saleId, $accountId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::PartiallyPaid, $sale->status);
            $this->assertSame('200.00', (string) $sale->collected_amount);
            $this->assertSame('400.00', $sale->outstandingAmount());

            $account = MoneyAccount::findOrFail($accountId);
            $this->assertSame('200.00', (string) $account->balance);

            $tx = AccountTransaction::query()
                ->where('reference_entity_type', 'sale')
                ->where('reference_entity_id', $saleId)
                ->first();

            $this->assertNotNull($tx);
            $this->assertSame(AccountTransactionType::ReceivablePayment, $tx->type);
            $this->assertSame('200.00', (string) $tx->amount);
        });

        $this->from("/sales/{$saleId}")
            ->post("/sales/{$saleId}/collect", [
                'amount' => '400.00',
                'money_account_id' => $accountId,
                'method' => 'bank',
                'reference_no' => 'RCV-2',
            ])
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($saleId, $accountId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::Paid, $sale->status);
            $this->assertSame('600.00', (string) $sale->collected_amount);
            $this->assertSame('0.00', $sale->outstandingAmount());
            $this->assertSame('600.00', (string) MoneyAccount::findOrFail($accountId)->balance);
            $this->assertSame(2, $sale->payments()->count());
        });

        $this->from("/sales/{$saleId}")
            ->post("/sales/{$saleId}/collect", [
                'amount' => '1.00',
                'money_account_id' => $accountId,
            ])
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasErrors('amount');
    }

    public function test_collection_rejects_overpayment_and_inactive_account(): void
    {
        $tenant = $this->seedTenant('sales-guards');
        $closed = $this->createClosedProjectWithProfit($tenant, '500.00');
        $saleId = $closed['sale']->id;
        $accountId = null;

        $this->post('/login', [
            'email' => 'admin@sales-guards.local',
            'password' => 'password',
        ]);

        $tenant->run(function () use (&$accountId, $saleId) {
            $account = app(MoneyAccountService::class)->createManagerAccount(
                'Ops Account',
                User::query()->first(),
            );
            $accountId = $account->id;

            app(SaleService::class)->convertToReceivable(
                Sale::findOrFail($saleId),
                User::query()->first(),
            );
        });

        $this->from("/sales/{$saleId}")
            ->post("/sales/{$saleId}/collect", [
                'amount' => '501.00',
                'money_account_id' => $accountId,
            ])
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasErrors('amount');

        $tenant->run(function () use ($accountId) {
            MoneyAccount::whereKey($accountId)->update(['is_active' => false]);
        });

        $this->from("/sales/{$saleId}")
            ->post("/sales/{$saleId}/collect", [
                'amount' => '100.00',
                'money_account_id' => $accountId,
            ])
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasErrors('amount');
    }

    public function test_unauthorized_user_cannot_convert_or_collect(): void
    {
        $tenant = Tenant::create([
            'name' => 'Sales Auth Co',
            'slug' => 'sales-auth',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@sales-auth.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Site Engineer',
            'email' => 'engineer@sales-auth.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();
        });

        tenancy()->end();

        $closed = $this->createClosedProjectWithProfit($tenant, '300.00');
        $saleId = $closed['sale']->id;

        $this->post('/login', [
            'email' => 'engineer@sales-auth.local',
            'password' => 'password',
        ]);

        $this->get('/sales')->assertForbidden();

        $this->post("/sales/{$saleId}/convert-receivable")->assertForbidden();
        $this->post("/sales/{$saleId}/collect", [
            'amount' => '100.00',
            'money_account_id' => 1,
        ])->assertForbidden();
    }

    public function test_project_show_includes_sale_link_payload(): void
    {
        $tenant = $this->seedTenant('sales-project-link');

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $project = Project::create([
                'code' => 'LINK-1',
                'name' => 'Linked',
                'client' => 'Customer',
                'location' => 'Site',
                'contract_amount' => '2000.00',
                'wht_percentage' => '0',
                'net_budget' => '2000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);
            $projectId = $project->id;
        });

        $this->post('/login', [
            'email' => 'admin@sales-project-link.local',
            'password' => 'password',
        ]);

        session(['current_project_id' => $projectId]);

        $this->get("/projects/{$projectId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Show')
                ->has('sale')
                ->where('sale.project.id', $projectId)
            );
    }
}
