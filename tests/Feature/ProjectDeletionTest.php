<?php

namespace Tests\Feature;

use App\Enums\DepositSource;
use App\Enums\ExpenseCategory;
use App\Enums\ProjectStatus;
use App\Enums\RequisitionStatus;
use App\Enums\SaleStatus;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Requisition;
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

class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(string $slug = 'purge-co'): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Purge Co',
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

    private function login(string $slug = 'purge-co'): void
    {
        $this->post('/login', [
            'email' => "admin@{$slug}.local",
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_wrong_confirmation_code_does_not_delete_project(): void
    {
        $tenant = $this->seedTenant('purge-wrong');

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $project = Project::create([
                'code' => 'PRG-WRONG',
                'name' => 'Keep Me',
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
            $projectId = $project->id;
        });

        $this->login('purge-wrong');

        $this->from("/projects/{$projectId}")
            ->delete("/projects/{$projectId}", ['confirmation_code' => 'WRONG'])
            ->assertRedirect("/projects/{$projectId}")
            ->assertSessionHasErrors('confirmation_code');

        $tenant->run(function () use ($projectId) {
            $this->assertDatabaseHas('projects', ['id' => $projectId, 'code' => 'PRG-WRONG']);
        });
    }

    public function test_permanent_delete_wipes_related_data_and_reverses_cash(): void
    {
        $tenant = $this->seedTenant('purge-cash');

        $ids = [];

        $tenant->run(function () use (&$ids) {
            $user = User::query()->firstOrFail();
            $money = app(MoneyAccountService::class);

            $project = Project::create([
                'code' => 'PRG-WIPE',
                'name' => 'Wipe Me',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000.00',
                'wht_percentage' => '0',
                'net_budget' => '10000.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => ProjectStatus::Active,
            ]);

            $phase = app(PhaseService::class)->create($project, [
                'name' => 'Phase 1',
                'disbursed_amount' => '5000.00',
            ]);

            $manager = $money->createManagerAccount('Company Main', $user, [
                'bank_name' => 'CRDB',
            ]);

            // Seed company balance via retention-release deposit for this project.
            $money->deposit($manager, '300.00', $user, [
                'deposit_source' => DepositSource::RetentionRelease,
                'description' => 'Retention release test',
                'reference_entity_type' => 'project',
                'reference_entity_id' => $project->id,
            ]);

            $sale = Sale::query()->where('phase_id', $phase->id)->firstOrFail();
            $sale->update([
                'status' => SaleStatus::Receivable,
                'profit_amount' => '400.00',
                'collected_amount' => '0.00',
                'converted_at' => now(),
                'converted_by' => $user->id,
            ]);

            app(SaleService::class)->collect($sale->fresh(), $user, [
                'money_account_id' => $manager->id,
                'amount' => '400.00',
                'method' => 'bank_transfer',
            ]);

            $finance = $money->ensureFinanceAccount($user);
            $finance->update(['balance' => '5000.00']);

            $expense = Expense::create([
                'project_id' => $project->id,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Materials',
                'amount' => '250.00',
                'description' => 'Site materials',
                'expense_date' => now()->toDateString(),
                'recorded_by' => $user->id,
            ]);

            $tx = $money->disburseFromFinance('250.00', $user, [
                'description' => 'Expense disbursement',
                'reference_entity_type' => 'expense',
                'reference_entity_id' => $expense->id,
                'method' => 'cash',
            ]);

            CashDisbursement::create([
                'expense_id' => $expense->id,
                'money_account_id' => $finance->id,
                'account_transaction_id' => $tx->id,
                'amount' => '250.00',
                'method' => 'cash',
                'payee' => 'Supplier',
                'disbursed_by' => $user->id,
                'disbursed_at' => now(),
                'created_at' => now(),
            ]);

            Requisition::create([
                'requisition_no' => 'REQ-PURGE-1',
                'project_id' => $project->id,
                'department' => 'Site',
                'resource_type' => 'other',
                'requestor_id' => $user->id,
                'status' => RequisitionStatus::Draft,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '100.00',
            ]);

            $ids = [
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'sale_id' => $sale->id,
                'manager_id' => $manager->id,
                'finance_id' => $finance->id,
                'expense_id' => $expense->id,
            ];

            $this->assertSame('700.00', (string) $manager->fresh()->balance);
            $this->assertSame('4750.00', (string) $finance->fresh()->balance);
        });

        $this->login('purge-cash');

        $this->delete("/projects/{$ids['project_id']}", [
            'confirmation_code' => 'PRG-WIPE',
        ])->assertRedirect('/projects')
            ->assertSessionHas('success', 'Project permanently deleted.');

        $tenant->run(function () use ($ids) {
            $this->assertDatabaseMissing('projects', ['id' => $ids['project_id']]);
            $this->assertDatabaseMissing('project_phases', ['id' => $ids['phase_id']]);
            $this->assertDatabaseMissing('sales', ['id' => $ids['sale_id']]);
            $this->assertDatabaseMissing('expenses', ['id' => $ids['expense_id']]);
            $this->assertSame(0, Requisition::withTrashed()->where('project_id', $ids['project_id'])->count());
            $this->assertSame(0, ProjectPhase::withTrashed()->where('project_id', $ids['project_id'])->count());

            // Receivable 400 + retention deposit 300 reversed off company account.
            $this->assertSame('0.00', (string) MoneyAccount::findOrFail($ids['manager_id'])->balance);
            // Disbursement 250 returned to finance.
            $this->assertSame('5000.00', (string) MoneyAccount::findOrFail($ids['finance_id'])->balance);
        });
    }

    public function test_user_without_delete_permission_cannot_purge_project(): void
    {
        $tenant = $this->seedTenant('purge-deny');

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Viewer',
            'email' => 'viewer@purge-deny.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'PRG-DENY',
                'name' => 'Protected',
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
        });

        tenancy()->end();

        $this->post('/login', [
            'email' => 'viewer@purge-deny.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $projectId = Project::where('code', 'PRG-DENY')->value('id');
        });

        $this->delete("/projects/{$projectId}", [
            'confirmation_code' => 'PRG-DENY',
        ])->assertForbidden();

        $tenant->run(function () use ($projectId) {
            $this->assertDatabaseHas('projects', ['id' => $projectId]);
        });
    }
}
