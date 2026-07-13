<?php

namespace App\Console\Commands;

use App\Enums\ApprovalStepStatus;
use App\Enums\BoqItemCategory;
use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Enums\ComplianceRuleType;
use App\Enums\FulfillmentType;
use App\Enums\ProjectStatus;
use App\Enums\RequisitionStatus;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\ProjectComplianceRule;
use App\Models\Requisition;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Console\Command;

class SeedDemoCommand extends Command
{
    protected $signature = 'tenant:seed-demo {tenant? : Tenant slug or ID}';

    protected $description = 'Seed demo project, BOQ, suppliers, inventory, and employees for a tenant';

    public function handle(PermissionService $permissionService): int
    {
        $identifier = $this->argument('tenant') ?? 'demo';
        $tenant = Tenant::where('slug', $identifier)->orWhere('id', $identifier)->first();

        if (! $tenant) {
            $this->error("Tenant not found: {$identifier}");

            return self::FAILURE;
        }

        $tenant->run(function () use ($permissionService) {
            $permissionService->syncTenantPermissions();

            $admin = User::role('System Administrator')->first() ?? User::first();

            $project = Project::firstOrCreate(
                ['code' => 'DEMO-001'],
                [
                    'name' => 'Demo Road Construction',
                    'client' => 'Tanzania Roads Agency',
                    'location' => 'Dar es Salaam',
                    'contract_amount' => '500000000.00',
                    'wht_percentage' => '5.00',
                    'physical_progress_pct' => '15.00',
                    'start_date' => now()->subMonths(2),
                    'end_date' => now()->addYear(),
                    'status' => ProjectStatus::Active,
                ],
            );

            Project::firstOrCreate(
                ['code' => 'DEMO-002'],
                [
                    'name' => 'Kigamboni Bridge Approach',
                    'client' => 'TANROADS',
                    'location' => 'Kigamboni',
                    'contract_amount' => '250000000.00',
                    'wht_percentage' => '5.00',
                    'physical_progress_pct' => '0.00',
                    'start_date' => now()->addMonth(),
                    'end_date' => now()->addMonths(18),
                    'status' => ProjectStatus::Planning,
                ],
            );

            foreach ([
                ['rule_type' => ComplianceRuleType::Retention, 'rate' => '10.00'],
                ['rule_type' => ComplianceRuleType::AdvanceRecovery, 'rate' => '15.00', 'max_amount' => '50000000.00'],
                ['rule_type' => ComplianceRuleType::Wht, 'rate' => '5.00'],
            ] as $rule) {
                ProjectComplianceRule::firstOrCreate(
                    ['project_id' => $project->id, 'rule_type' => $rule['rule_type']],
                    array_merge($rule, ['is_active' => true]),
                );
            }

            $section = BoqSection::firstOrCreate(
                ['project_id' => $project->id, 'name' => 'Earthworks'],
                ['display_order' => 1],
            );

            $items = [
                ['description' => 'Excavation - bulk', 'unit' => 'm3', 'category' => BoqItemCategory::Materials, 'budgeted_qty' => '5000', 'unit_rate' => '8500', 'consumed_qty' => '800'],
                ['description' => 'Gravel fill', 'unit' => 'm3', 'category' => BoqItemCategory::Materials, 'budgeted_qty' => '3000', 'unit_rate' => '12000', 'consumed_qty' => '250'],
                ['description' => 'Site labour', 'unit' => 'day', 'category' => BoqItemCategory::Labor, 'budgeted_qty' => '200', 'unit_rate' => '45000', 'consumed_qty' => '30'],
            ];

            $boqItems = collect();

            foreach ($items as $item) {
                $boqItems->push(BoqItem::firstOrCreate(
                    ['section_id' => $section->id, 'description' => $item['description']],
                    array_merge($item, [
                        'budgeted_amount' => bcmul($item['budgeted_qty'], $item['unit_rate'], 2),
                        'reserved_qty' => '0',
                    ]),
                ));
            }

            $excavation = $boqItems->firstWhere('description', 'Excavation - bulk');
            $gravel = $boqItems->firstWhere('description', 'Gravel fill');

            Supplier::firstOrCreate(['name' => 'Kilimanjaro Supplies Ltd'], ['contact_info' => 'info@kilimanjaro.co.tz']);
            Supplier::firstOrCreate(['name' => 'Coastal Cement Co.'], ['contact_info' => 'sales@coastalcement.co.tz']);

            $cement = InventoryItem::firstOrCreate(['code' => 'CEM-50KG'], [
                'name' => 'Portland Cement 50kg',
                'unit' => 'bag',
                'category' => 'materials',
                'reorder_point' => '100',
            ]);

            $store = StockLocation::firstOrCreate(
                ['project_id' => $project->id, 'name' => 'Main Site Store'],
            );

            StockBalance::updateOrCreate(
                ['inventory_item_id' => $cement->id, 'stock_location_id' => $store->id],
                [
                    'quantity_on_hand' => '450.0000',
                    'average_cost' => '18500.00',
                    'updated_at' => now(),
                ],
            );

            Employee::firstOrCreate(['employee_no' => 'EMP-001'], [
                'name' => 'John Mwangi',
                'role' => 'Site Foreman',
                'pay_structure' => 'monthly',
                'monthly_salary' => '1200000.00',
                'project_id' => $project->id,
            ]);

            Employee::firstOrCreate(['employee_no' => 'EMP-002'], [
                'name' => 'Amina Hassan',
                'role' => 'Quantity Surveyor',
                'pay_structure' => 'monthly',
                'monthly_salary' => '1800000.00',
                'project_id' => $project->id,
            ]);

            Equipment::firstOrCreate(['name' => 'CAT 320 Excavator'], [
                'type' => 'Excavator',
                'status' => 'available',
            ]);

            Equipment::firstOrCreate(['name' => 'Isuzu FVZ Tipper'], [
                'type' => 'Tipper Truck',
                'status' => 'assigned',
            ]);

            if ($admin) {
                CashAllocation::firstOrCreate(
                    ['project_id' => $project->id, 'reference_no' => 'CASH-DEMO-001'],
                    [
                        'requested_amount' => '50000000.00',
                        'received_amount' => '50000000.00',
                        'utilized_amount' => '12500000.00',
                        'status' => CashAllocationStatus::Received,
                        'requested_by' => $admin->id,
                        'approved_by' => $admin->id,
                        'method' => 'bank_transfer',
                        'requested_at' => now()->subWeeks(2),
                        'received_at' => now()->subWeeks(2),
                    ],
                );

                $approvedReq = Requisition::firstOrCreate(
                    ['requisition_no' => 'REQ-DEMO-001'],
                    [
                        'project_id' => $project->id,
                        'boq_item_id' => $excavation->id,
                        'department' => 'Site Operations',
                        'requestor_id' => $admin->id,
                        'status' => RequisitionStatus::Approved,
                        'fulfillment_type' => FulfillmentType::CashDisbursement,
                        'original_amount' => '8500000.00',
                    ],
                );

                Requisition::firstOrCreate(
                    ['requisition_no' => 'REQ-DEMO-002'],
                    [
                        'project_id' => $project->id,
                        'boq_item_id' => $gravel->id,
                        'department' => 'Procurement',
                        'requestor_id' => $admin->id,
                        'status' => RequisitionStatus::UnderReview,
                        'fulfillment_type' => FulfillmentType::DirectSupplierPayment,
                        'original_amount' => '3600000.00',
                    ],
                );

                Requisition::firstOrCreate(
                    ['requisition_no' => 'REQ-DEMO-003'],
                    [
                        'project_id' => $project->id,
                        'boq_item_id' => $excavation->id,
                        'department' => 'Site Operations',
                        'requestor_id' => $admin->id,
                        'status' => RequisitionStatus::Draft,
                        'fulfillment_type' => FulfillmentType::StockIssue,
                        'original_amount' => '1200000.00',
                    ],
                );

                $pendingReq = Requisition::where('requisition_no', 'REQ-DEMO-002')->first();

                if ($pendingReq) {
                    ApprovalStep::firstOrCreate(
                        ['requisition_id' => $pendingReq->id, 'level' => 1],
                        [
                            'required_role' => 'Project Manager',
                            'status' => ApprovalStepStatus::Pending,
                            'assigned_at' => now()->subDays(2),
                        ],
                    );
                }

                BudgetTransaction::firstOrCreate(
                    [
                        'project_id' => $project->id,
                        'boq_item_id' => $excavation->id,
                        'type' => BudgetTransactionType::ApprovedRequisition,
                        'reference_entity_type' => Requisition::class,
                        'reference_entity_id' => $approvedReq->id,
                    ],
                    [
                        'amount' => '8500000.00',
                        'reason' => 'Approved requisition REQ-DEMO-001',
                        'created_by' => $admin->id,
                        'created_at' => now()->subWeek(),
                    ],
                );

                BudgetTransaction::firstOrCreate(
                    [
                        'project_id' => $project->id,
                        'boq_item_id' => $excavation->id,
                        'type' => BudgetTransactionType::Purchase,
                    ],
                    [
                        'amount' => '4000000.00',
                        'reason' => 'Bulk excavation subcontract',
                        'created_by' => $admin->id,
                        'created_at' => now()->subDays(10),
                    ],
                );
            }

            $this->info("Demo data seeded for project [{$project->code}] — net budget: TZS {$project->net_budget}");
            $this->line('  • 2 projects, 3 BOQ items, 2 suppliers, 2 employees, 2 equipment');
            $this->line('  • Cash allocation, 3 requisitions (draft / under review / approved), stock balance');
        });

        return self::SUCCESS;
    }
}
