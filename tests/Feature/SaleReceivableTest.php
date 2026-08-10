<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionType;
use App\Enums\BudgetTransactionType;
use App\Enums\MoneyAccountType;
use App\Enums\PhaseStatus;
use App\Enums\ProjectStatus;
use App\Enums\SaleStatus;
use App\Models\AccountTransaction;
use App\Models\BudgetTransaction;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\PermissionService;
use App\Services\PhaseService;
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
     * @return array{project: Project, phase: ProjectPhase, sale: Sale, userId: int}
     */
    private function createProjectWithClosedPhaseAndProfit(
        Tenant $tenant,
        string $profit = '600.00',
        string $disbursed = '1000.00',
    ): array {
        $result = [];

        $tenant->run(function () use (&$result, $profit, $disbursed) {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'SALE-P1',
                'name' => 'Highway Extension',
                'client' => 'Ministry of Works',
                'location' => 'Dar',
                'contract_amount' => $disbursed,
                'wht_percentage' => '0',
                'net_budget' => $disbursed,
                'physical_progress_pct' => '100',
                'start_date' => now()->subYear(),
                'end_date' => now(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Phase 1',
                'disbursed_amount' => $disbursed,
            ]);

            app(PhaseService::class)->close($phase);
            $phase = $phase->fresh();

            $spend = bcsub($disbursed, $profit, 2);
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

            $sale = app(SaleService::class)->ensureForPhase($phase->fresh());

            $result = [
                'project' => $project->fresh(),
                'phase' => $phase,
                'sale' => $sale,
                'userId' => $userId,
            ];
        });

        return $result;
    }

    public function test_sales_index_lists_phase_sales_with_stable_ids(): void
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

            app(PhaseService::class)->create($project, [
                'name' => 'Foundation',
                'disbursed_amount' => '5000.00',
            ]);
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
                ->where('sales.data.0.phase.name', 'Foundation')
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

            app(PhaseService::class)->create($archived, [
                'name' => 'Phase 1',
                'disbursed_amount' => '1000.00',
            ]);
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

    public function test_open_phase_cannot_convert_and_closed_phase_can(): void
    {
        $tenant = $this->seedTenant('sales-convert');

        $openSaleId = null;
        $closedSaleId = null;

        $tenant->run(function () use (&$openSaleId) {
            $project = Project::create([
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

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Open Phase',
                'disbursed_amount' => '1000.00',
            ]);

            $openSaleId = app(SaleService::class)->ensureForPhase($phase)->id;
        });

        $closed = $this->createProjectWithClosedPhaseAndProfit($tenant, '600.00');
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

    public function test_closing_phase_endpoint_sets_closed_status_and_converts_receivable(): void
    {
        $tenant = $this->seedTenant('sales-close-phase');

        $projectId = null;
        $phaseId = null;
        $saleId = null;

        $tenant->run(function () use (&$projectId, &$phaseId, &$saleId) {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'CLOSE-1',
                'name' => 'Closable',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '2000.00',
                'wht_percentage' => '0',
                'net_budget' => '2000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Works',
                'disbursed_amount' => '2000.00',
            ]);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '500.00',
                'reason' => 'Site spend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            $projectId = $project->id;
            $phaseId = $phase->id;
            $saleId = app(SaleService::class)->ensureForPhase($phase)->id;
        });

        $this->post('/login', [
            'email' => 'admin@sales-close-phase.local',
            'password' => 'password',
        ]);

        $this->from("/projects/{$projectId}/phases/{$phaseId}")
            ->post("/projects/{$projectId}/phases/{$phaseId}/close")
            ->assertRedirect("/projects/{$projectId}/phases/{$phaseId}")
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($phaseId, $saleId) {
            $this->assertSame(PhaseStatus::Closed, ProjectPhase::findOrFail($phaseId)->status);

            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::Receivable, $sale->status);
            $this->assertSame('1500.00', (string) $sale->profit_amount);
            $this->assertSame('0.00', (string) $sale->collected_amount);
            $this->assertNotNull($sale->converted_at);
            $this->assertNotNull($sale->converted_by);
        });
    }

    public function test_closing_phase_with_deficit_carries_to_pending_deficit(): void
    {
        $tenant = $this->seedTenant('sales-close-zero');

        $phaseId = null;
        $saleId = null;
        $projectId = null;

        $tenant->run(function () use (&$phaseId, &$saleId, &$projectId) {
            $userId = (int) User::query()->value('id');
            $actor = User::findOrFail($userId);

            $project = Project::create([
                'code' => 'CLOSE-0',
                'name' => 'Break-even',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Works',
                'disbursed_amount' => '1000.00',
            ]);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '1000.00',
                'reason' => 'Full spend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            $saleId = app(SaleService::class)->ensureForPhase($phase)->id;
            app(PhaseService::class)->close($phase, $actor);
            $phaseId = $phase->id;
            $projectId = $project->id;
        });

        $tenant->run(function () use ($phaseId, $saleId, $projectId) {
            $this->assertSame(PhaseStatus::Closed, ProjectPhase::findOrFail($phaseId)->status);
            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::Open, $sale->status);
            $this->assertNull($sale->profit_amount);
            // Break-even: no positive remaining, pending deficit stays 0.
            $this->assertSame('0.00', (string) Project::findOrFail($projectId)->pending_deficit);
        });
    }

    public function test_closing_overspent_phase_sets_pending_deficit_without_receivable(): void
    {
        $tenant = $this->seedTenant('sales-close-overspend');

        $saleId = null;
        $projectId = null;

        $tenant->run(function () use (&$saleId, &$projectId) {
            $userId = (int) User::query()->value('id');
            $actor = User::findOrFail($userId);

            $project = Project::create([
                'code' => 'OVER-1',
                'name' => 'Overspent',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Works',
                'disbursed_amount' => '1000.00',
            ]);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '1001.00',
                'reason' => 'Overspend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            $saleId = app(SaleService::class)->ensureForPhase($phase)->id;
            app(PhaseService::class)->close($phase, $actor);
            $projectId = $project->id;
        });

        $tenant->run(function () use ($saleId, $projectId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::Open, $sale->status);
            $this->assertNull($sale->profit_amount);
            $this->assertSame('1.00', (string) Project::findOrFail($projectId)->pending_deficit);
            $this->assertSame(ProjectStatus::Active, Project::findOrFail($projectId)->status);
        });
    }

    public function test_deficit_from_first_phase_reduces_second_phase_receivable(): void
    {
        $tenant = $this->seedTenant('sales-carry-deficit');

        $tenant->run(function () {
            $userId = (int) User::query()->value('id');
            $actor = User::findOrFail($userId);

            $project = Project::create([
                'code' => 'CARRY-1',
                'name' => 'Carry Job',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '2000.00',
                'wht_percentage' => '0',
                'net_budget' => '2000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase1 = app(PhaseService::class)->create($project, [
                'name' => 'Phase A',
                'disbursed_amount' => '1000.00',
            ]);
            $phase2 = app(PhaseService::class)->create($project, [
                'name' => 'Phase B',
                'disbursed_amount' => '1000.00',
            ]);

            // Spend 1500 against 2000 budget → remaining 500.
            // Close phase 1 while both open: gross share = 250, converts 250, clears deficit.
            // Better scenario: overspend first so close P1 carries deficit, then add budget recovery... 
            // Simpler: spend 1200 so remaining 800. Close P1 alone with both open → share 400.
            // We need P1 close with underwater, then later remaining becomes positive for P2.
            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '2100.00',
                'reason' => 'Overspend early',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            app(PhaseService::class)->close($phase1, $actor);
            $this->assertSame(SaleStatus::Open, app(SaleService::class)->ensureForPhase($phase1->fresh())->status);
            $this->assertSame('100.00', (string) $project->fresh()->pending_deficit);

            // Reverse 600 of spend → remaining = 2000 - 1500 = 500; pending still 100.
            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::ManualAdjustment,
                'amount' => '-600.00',
                'reason' => 'Recover spend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            app(PhaseService::class)->close($phase2, $actor);
            $sale2 = app(SaleService::class)->ensureForPhase($phase2->fresh());
            // Only P2 open among unconverted when closing P2? P1 still open.
            // liveRemaining = 500. Both P1 and P2 open → P2 gross = 250. net = 250 - 100 = 150.
            $this->assertSame(SaleStatus::Receivable, $sale2->fresh()->status);
            $this->assertSame('150.00', (string) $sale2->fresh()->profit_amount);
            $this->assertSame('0.00', (string) $project->fresh()->pending_deficit);
        });
    }

    public function test_mark_project_as_loss_creates_negative_receivable(): void
    {
        $tenant = $this->seedTenant('sales-mark-loss');

        $projectId = null;
        $saleId = null;

        $tenant->run(function () use (&$projectId, &$saleId) {
            $userId = (int) User::query()->value('id');
            $actor = User::findOrFail($userId);

            $project = Project::create([
                'code' => 'LOSS-1',
                'name' => 'Loss Job',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Works',
                'disbursed_amount' => '1000.00',
            ]);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '1300.00',
                'reason' => 'Overspend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            app(PhaseService::class)->close($phase, $actor);
            $saleId = app(SaleService::class)->ensureForPhase($phase->fresh())->id;
            $projectId = $project->id;

            $this->assertSame('300.00', (string) $project->fresh()->pending_deficit);
        });

        $this->post('/login', [
            'email' => 'admin@sales-mark-loss.local',
            'password' => 'password',
        ]);

        $this->from("/projects/{$projectId}")
            ->post("/projects/{$projectId}/mark-loss")
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($projectId, $saleId) {
            $project = Project::findOrFail($projectId);
            $this->assertSame(ProjectStatus::Loss, $project->status);
            $this->assertSame('0.00', (string) $project->pending_deficit);
            $this->assertNotNull($project->marked_loss_at);

            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::Receivable, $sale->status);
            $this->assertSame('-300.00', (string) $sale->profit_amount);
            $this->assertTrue($sale->isLossReceivable());
        });
    }

    public function test_collect_negative_loss_debits_company_account(): void
    {
        $tenant = $this->seedTenant('sales-loss-collect');

        $saleId = null;
        $accountId = null;

        $tenant->run(function () use (&$saleId, &$accountId) {
            $userId = (int) User::query()->value('id');
            $actor = User::findOrFail($userId);

            $project = Project::create([
                'code' => 'LOSS-C',
                'name' => 'Loss Collect',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Works',
                'disbursed_amount' => '1000.00',
            ]);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '1250.00',
                'reason' => 'Overspend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            app(PhaseService::class)->close($phase, $actor);
            $sale = app(SaleService::class)->markProjectAsLoss($project->fresh(), $actor);
            $saleId = $sale->id;

            $account = app(MoneyAccountService::class)->createManagerAccount(
                'Company Main',
                User::query()->first(),
            );
            $account->update(['balance' => '5000.00']);
            $accountId = $account->id;
        });

        $this->post('/login', [
            'email' => 'admin@sales-loss-collect.local',
            'password' => 'password',
        ]);

        $this->from("/sales/{$saleId}")
            ->post("/sales/{$saleId}/collect", [
                'amount' => '-250.00',
                'money_account_id' => $accountId,
            ])
            ->assertRedirect("/sales/{$saleId}")
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($saleId, $accountId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertSame(SaleStatus::Paid, $sale->status);
            $this->assertSame('-250.00', (string) $sale->collected_amount);
            $this->assertSame('0.00', $sale->outstandingAmount());
            $this->assertSame('4750.00', (string) MoneyAccount::findOrFail($accountId)->balance);

            $tx = AccountTransaction::query()
                ->where('money_account_id', $accountId)
                ->where('type', AccountTransactionType::ReceivablePayment)
                ->latest('id')
                ->first();
            $this->assertNotNull($tx);
            $this->assertSame('-250.00', (string) $tx->amount);
        });
    }

    public function test_mark_loss_rejected_when_no_deficit(): void
    {
        $tenant = $this->seedTenant('sales-mark-loss-none');

        $projectId = null;

        $tenant->run(function () use (&$projectId) {
            $userId = (int) User::query()->value('id');
            $actor = User::findOrFail($userId);

            $project = Project::create([
                'code' => 'LOSS-0',
                'name' => 'Healthy',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Works',
                'disbursed_amount' => '1000.00',
            ]);
            app(PhaseService::class)->close($phase, $actor);
            $projectId = $project->id;
        });

        $this->post('/login', [
            'email' => 'admin@sales-mark-loss-none.local',
            'password' => 'password',
        ]);

        $this->from("/projects/{$projectId}")
            ->post("/projects/{$projectId}/mark-loss")
            ->assertRedirect("/projects/{$projectId}")
            ->assertSessionHasErrors('loss');
    }

    public function test_two_phases_convert_pro_rata_of_recognizable_profit(): void
    {
        $tenant = $this->seedTenant('sales-prorata');

        $sale1Id = null;
        $sale2Id = null;
        $userId = null;

        $tenant->run(function () use (&$sale1Id, &$sale2Id, &$userId) {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'PRORATA',
                'name' => 'Two Phase Job',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '50',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase1 = app(PhaseService::class)->create($project, [
                'name' => 'Phase A',
                'disbursed_amount' => '400.00',
            ]);
            $phase2 = app(PhaseService::class)->create($project, [
                'name' => 'Phase B',
                'disbursed_amount' => '600.00',
            ]);

            // Remaining profit = 1000 (no spend).
            app(PhaseService::class)->close($phase1);
            app(PhaseService::class)->close($phase2);

            $sale1 = app(SaleService::class)->ensureForPhase($phase1->fresh());
            $sale2 = app(SaleService::class)->ensureForPhase($phase2->fresh());
            $sale1Id = $sale1->id;
            $sale2Id = $sale2->id;

            $actor = User::findOrFail($userId);
            app(SaleService::class)->convertToReceivable($sale1, $actor);
        });

        $tenant->run(function () use ($sale1Id, $sale2Id, $userId) {
            $sale1 = Sale::findOrFail($sale1Id);
            $this->assertSame('400.00', (string) $sale1->profit_amount);

            $actor = User::findOrFail($userId);
            app(SaleService::class)->convertToReceivable(Sale::findOrFail($sale2Id), $actor);

            $sale2 = Sale::findOrFail($sale2Id);
            $this->assertSame('600.00', (string) $sale2->profit_amount);
        });
    }

    public function test_mid_stream_spend_reduces_later_phase_share(): void
    {
        $tenant = $this->seedTenant('sales-spend');

        $tenant->run(function () {
            $userId = (int) User::query()->value('id');

            $project = Project::create([
                'code' => 'SPEND-1',
                'name' => 'Spend Job',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '50',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase1 = app(PhaseService::class)->create($project, [
                'name' => 'Phase A',
                'disbursed_amount' => '400.00',
            ]);
            $phase2 = app(PhaseService::class)->create($project, [
                'name' => 'Phase B',
                'disbursed_amount' => '600.00',
            ]);

            app(PhaseService::class)->close($phase1);
            app(PhaseService::class)->close($phase2);

            $actor = User::findOrFail($userId);
            $sale1 = app(SaleService::class)->ensureForPhase($phase1->fresh());
            app(SaleService::class)->convertToReceivable($sale1, $actor);
            $this->assertSame('400.00', (string) $sale1->fresh()->profit_amount);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::DirectExpense,
                'amount' => '300.00',
                'reason' => 'Extra spend',
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            // Remaining 700, recognized 400 → recognizable 300, all to phase 2.
            $sale2 = app(SaleService::class)->ensureForPhase($phase2->fresh());
            app(SaleService::class)->convertToReceivable($sale2, $actor);
            $this->assertSame('300.00', (string) $sale2->fresh()->profit_amount);
        });
    }

    public function test_partial_and_full_collections_update_account_and_outstanding(): void
    {
        $tenant = $this->seedTenant('sales-collect');
        $closed = $this->createProjectWithClosedPhaseAndProfit($tenant, '600.00');
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
        $closed = $this->createProjectWithClosedPhaseAndProfit($tenant, '500.00');
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

        $closed = $this->createProjectWithClosedPhaseAndProfit($tenant, '300.00');
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

    public function test_project_show_includes_sales_payload(): void
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

            app(PhaseService::class)->create($project, [
                'name' => 'Phase 1',
                'disbursed_amount' => '2000.00',
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
                ->has('sales', 1)
                ->where('sales.0.project.id', $projectId)
                ->where('sales.0.phase.name', 'Phase 1')
            );
    }

    public function test_convert_does_not_require_project_closed(): void
    {
        $tenant = $this->seedTenant('sales-active-ok');
        $fixture = $this->createProjectWithClosedPhaseAndProfit($tenant, '250.00');

        $tenant->run(function () use ($fixture) {
            $this->assertSame(ProjectStatus::Active, $fixture['project']->fresh()->status);

            app(SaleService::class)->convertToReceivable(
                $fixture['sale']->fresh(),
                User::findOrFail($fixture['userId']),
            );

            $this->assertSame(SaleStatus::Receivable, $fixture['sale']->fresh()->status);
            $this->assertSame('250.00', (string) $fixture['sale']->fresh()->profit_amount);
        });
    }
}
