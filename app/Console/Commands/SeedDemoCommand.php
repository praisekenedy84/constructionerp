<?php

namespace App\Console\Commands;

use App\Enums\ApprovalStepStatus;
use App\Enums\BoqItemCategory;
use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Enums\FulfillmentType;
use App\Enums\ProjectStatus;
use App\Enums\RequisitionResourceType;
use App\Enums\RequisitionStatus;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\ComplianceRule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionCategory;
use App\Models\RequisitionItem;
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

            foreach (['Retention', 'Advance Recovery', 'WHT', 'Defect Liability', 'Material Test'] as $ruleName) {
                ComplianceRule::firstOrCreate(
                    ['name' => $ruleName],
                    ['description' => null, 'is_active' => true],
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
            $siteLabour = $boqItems->firstWhere('description', 'Site labour');

            Supplier::firstOrCreate(['name' => 'Kilimanjaro Supplies Ltd'], ['contact_info' => 'info@kilimanjaro.co.tz']);
            Supplier::firstOrCreate(['name' => 'Coastal Cement Co.'], ['contact_info' => 'sales@coastalcement.co.tz']);

            $cement = InventoryItem::firstOrCreate(['code' => 'CEM-50KG'], [
                'name' => 'Portland Cement 50kg',
                'unit' => 'bag',
                'category' => 'materials',
                'reorder_point' => '100',
            ]);

            $diesel = InventoryItem::firstOrCreate(['code' => 'DSL-L'], [
                'name' => 'Diesel fuel',
                'unit' => 'L',
                'category' => 'fuel',
                'reorder_point' => '500',
            ]);

            $rebar = InventoryItem::firstOrCreate(['code' => 'RBR-16'], [
                'name' => 'Reinforcement bar 16mm',
                'unit' => 'pcs',
                'category' => 'materials',
                'reorder_point' => '50',
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

            StockBalance::updateOrCreate(
                ['inventory_item_id' => $diesel->id, 'stock_location_id' => $store->id],
                [
                    'quantity_on_hand' => '2200.0000',
                    'average_cost' => '3200.00',
                    'updated_at' => now(),
                ],
            );

            StockBalance::updateOrCreate(
                ['inventory_item_id' => $rebar->id, 'stock_location_id' => $store->id],
                [
                    'quantity_on_hand' => '180.0000',
                    'average_cost' => '45000.00',
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
                $engineer1 = User::where('email', 'engineer@demo.local')->first();
                $engineer2 = User::where('email', 'engineer2@demo.local')->first();
                $engineer3 = User::where('email', 'engineer3@demo.local')->first();
                $authorA = $engineer1?->id ?? $admin->id;
                $authorB = $engineer2?->id ?? $admin->id;
                $authorC = $engineer3?->id ?? $admin->id;

                CashAllocation::firstOrCreate(
                    ['project_id' => $project->id, 'reference_no' => 'CASH-DEMO-001'],
                    [
                        'requested_amount' => '50000000.00',
                        'received_amount' => '50000000.00',
                        'utilized_amount' => '12500000.00',
                        'opening_utilized_amount' => '12500000.00',
                        'status' => CashAllocationStatus::Received,
                        'requested_by' => $admin->id,
                        'approved_by' => $admin->id,
                        'method' => 'bank',
                        'requested_at' => now()->subWeeks(2),
                        'received_at' => now()->subWeeks(2),
                    ],
                );

                $categoryIds = RequisitionCategory::query()
                    ->get(['id', 'name'])
                    ->mapWithKeys(fn (RequisitionCategory $category) => [
                        $category->name => $category->id,
                    ]);

                $departmentIds = Department::query()
                    ->get(['id', 'name'])
                    ->mapWithKeys(fn (Department $department) => [
                        $department->name => $department->id,
                    ]);

                $demoRequisitions = [
                    [
                        'requisition_no' => 'REQ-DEMO-001',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => null,
                            'department' => 'Site Operations',
                            'resource_type' => RequisitionResourceType::Cash,
                            'requestor_id' => $authorA,
                            'status' => RequisitionStatus::Approved,
                            'fulfillment_type' => FulfillmentType::CashDisbursement,
                            'original_amount' => '850000.00',
                        ],
                        'items' => [
                            [
                                'description' => 'Petty cash for local transport and site incidentals',
                                'unit' => 'lump',
                                'quantity' => '1.0000',
                                'unit_cost' => '850000.00',
                                'line_total' => '850000.00',
                                'details' => ['estimated_amount' => '850000.00'],
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-002',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => $gravel->id,
                            'department' => 'Procurement',
                            'resource_type' => RequisitionResourceType::Materials,
                            'requestor_id' => $authorB,
                            'status' => RequisitionStatus::UnderReview,
                            'fulfillment_type' => FulfillmentType::DirectSupplierPayment,
                            'original_amount' => '3600000.00',
                        ],
                        'items' => [
                            [
                                'boq_item_id' => $gravel->id,
                                'description' => 'Gravel fill — supplier delivery',
                                'unit' => 'm3',
                                'quantity' => '300.0000',
                                'unit_cost' => '12000.00',
                                'line_total' => '3600000.00',
                                'details' => null,
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-003',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => $excavation->id,
                            'department' => 'Site Stores',
                            'resource_type' => RequisitionResourceType::Materials,
                            'requestor_id' => $authorA,
                            'status' => RequisitionStatus::Draft,
                            'fulfillment_type' => FulfillmentType::StockIssue,
                            'original_amount' => '925000.00',
                        ],
                        'items' => [
                            [
                                'boq_item_id' => $excavation->id,
                                'inventory_item_id' => $cement->id,
                                'description' => 'Portland Cement 50kg',
                                'unit' => 'bag',
                                'quantity' => '50.0000',
                                'unit_cost' => '18500.00',
                                'line_total' => '925000.00',
                                'details' => null,
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-004',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => $siteLabour?->id,
                            'department' => 'Site Operations',
                            'resource_type' => RequisitionResourceType::Labor,
                            'requestor_id' => $authorC,
                            'status' => RequisitionStatus::UnderReview,
                            'fulfillment_type' => FulfillmentType::CashDisbursement,
                            'original_amount' => '1250000.00',
                        ],
                        'items' => [
                            [
                                'boq_item_id' => $siteLabour?->id,
                                'description' => 'Casual workers for excavation support',
                                'unit' => 'worker-day',
                                'quantity' => '50.0000',
                                'unit_cost' => '25000.00',
                                'line_total' => '1250000.00',
                                'details' => [
                                    'workers' => '10.0000',
                                    'days' => '5.0000',
                                    'rate_per_day' => '25000.00',
                                ],
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-005',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => $excavation->id,
                            'department' => 'Plant',
                            'resource_type' => RequisitionResourceType::Equipment,
                            'requestor_id' => $authorB,
                            'status' => RequisitionStatus::Approved,
                            'fulfillment_type' => FulfillmentType::DirectSupplierPayment,
                            'original_amount' => '2400000.00',
                        ],
                        'items' => [
                            [
                                'boq_item_id' => $excavation->id,
                                'description' => 'CAT 320 Excavator hire',
                                'unit' => 'day',
                                'quantity' => '3.0000',
                                'unit_cost' => '800000.00',
                                'line_total' => '2400000.00',
                                'details' => [
                                    'duration' => '3.0000',
                                    'duration_unit' => 'day',
                                    'rate' => '800000.00',
                                ],
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-006',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => null,
                            'department' => 'Logistics',
                            'resource_type' => RequisitionResourceType::Transport,
                            'requestor_id' => $authorB,
                            'status' => RequisitionStatus::Draft,
                            'fulfillment_type' => FulfillmentType::DirectSupplierPayment,
                            'original_amount' => '480000.00',
                        ],
                        'items' => [
                            [
                                'description' => 'Aggregate haulage from quarry to site',
                                'unit' => 'trip',
                                'quantity' => '6.0000',
                                'unit_cost' => '80000.00',
                                'line_total' => '480000.00',
                                'details' => [
                                    'trips' => '6.0000',
                                    'cost_per_trip' => '80000.00',
                                ],
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-007',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => null,
                            'department' => 'Plant',
                            'resource_type' => RequisitionResourceType::Fuel,
                            'requestor_id' => $authorC,
                            'status' => RequisitionStatus::Draft,
                            'fulfillment_type' => FulfillmentType::StockIssue,
                            'original_amount' => '640000.00',
                        ],
                        'items' => [
                            [
                                'inventory_item_id' => $diesel->id,
                                'description' => 'Diesel fuel',
                                'unit' => 'L',
                                'quantity' => '200.0000',
                                'unit_cost' => '3200.00',
                                'line_total' => '640000.00',
                                'details' => null,
                            ],
                        ],
                    ],
                    [
                        'requisition_no' => 'REQ-DEMO-008',
                        'header' => [
                            'project_id' => $project->id,
                            'boq_item_id' => null,
                            'department' => 'Site Stores',
                            'resource_type' => RequisitionResourceType::Materials,
                            'requestor_id' => $authorA,
                            'status' => RequisitionStatus::Draft,
                            'fulfillment_type' => FulfillmentType::DirectSupplierPayment,
                            'original_amount' => '375000.00',
                        ],
                        'items' => [
                            [
                                'description' => 'Safety helmets (new item — not in catalog)',
                                'unit' => 'pcs',
                                'quantity' => '25.0000',
                                'unit_cost' => '15000.00',
                                'line_total' => '375000.00',
                                'details' => null,
                            ],
                        ],
                    ],
                ];

                foreach ($demoRequisitions as $demo) {
                    $resourceType = $demo['header']['resource_type'] instanceof RequisitionResourceType
                        ? $demo['header']['resource_type']
                        : RequisitionResourceType::from((string) $demo['header']['resource_type']);

                    $header = [
                        ...$demo['header'],
                        'requisition_category_id' => $categoryIds[$resourceType->label()] ?? null,
                        'department_id' => $departmentIds[$demo['header']['department']] ?? null,
                    ];

                    $requisition = Requisition::firstOrCreate(
                        ['requisition_no' => $demo['requisition_no']],
                        $header,
                    );

                    // Keep older demo rows aligned with the flexible model + authors.
                    $requisition->fill([
                        'resource_type' => $header['resource_type'],
                        'requisition_category_id' => $header['requisition_category_id'],
                        'boq_item_id' => $header['boq_item_id'],
                        'original_amount' => $header['original_amount'],
                        'department' => $header['department'],
                        'department_id' => $header['department_id'],
                        'fulfillment_type' => $header['fulfillment_type'],
                        'status' => $header['status'],
                        'requestor_id' => $header['requestor_id'],
                    ])->save();

                    if ($requisition->items()->exists()) {
                        continue;
                    }

                    foreach ($demo['items'] as $item) {
                        RequisitionItem::create([
                            'requisition_id' => $requisition->id,
                            'boq_item_id' => $item['boq_item_id'] ?? $header['boq_item_id'],
                            'inventory_item_id' => $item['inventory_item_id'] ?? null,
                            'description' => $item['description'],
                            'unit' => $item['unit'],
                            'quantity' => $item['quantity'],
                            'unit_cost' => $item['unit_cost'],
                            'line_total' => $item['line_total'],
                            'details' => $item['details'],
                        ]);
                    }
                }

                $pendingMaterials = Requisition::where('requisition_no', 'REQ-DEMO-002')->first();
                $pendingLabour = Requisition::where('requisition_no', 'REQ-DEMO-004')->first();

                foreach ([$pendingMaterials, $pendingLabour] as $pendingReq) {
                    if (! $pendingReq) {
                        continue;
                    }

                    ApprovalStep::firstOrCreate(
                        ['requisition_id' => $pendingReq->id, 'level' => 1],
                        [
                            'required_role' => 'Project Manager',
                            'status' => ApprovalStepStatus::Pending,
                            'assigned_at' => now()->subDays(2),
                        ],
                    );
                }

                $approvedCash = Requisition::where('requisition_no', 'REQ-DEMO-001')->first();
                $approvedEquipment = Requisition::where('requisition_no', 'REQ-DEMO-005')->first();

                if ($approvedCash) {
                    BudgetTransaction::firstOrCreate(
                        [
                            'project_id' => $project->id,
                            'type' => BudgetTransactionType::ApprovedRequisition,
                            'reference_entity_type' => Requisition::class,
                            'reference_entity_id' => $approvedCash->id,
                        ],
                        [
                            'boq_item_id' => null,
                            'amount' => '850000.00',
                            'reason' => 'Approved cash requisition REQ-DEMO-001',
                            'created_by' => $admin->id,
                            'created_at' => now()->subWeek(),
                        ],
                    );
                }

                if ($approvedEquipment) {
                    BudgetTransaction::firstOrCreate(
                        [
                            'project_id' => $project->id,
                            'boq_item_id' => $excavation->id,
                            'type' => BudgetTransactionType::ApprovedRequisition,
                            'reference_entity_type' => Requisition::class,
                            'reference_entity_id' => $approvedEquipment->id,
                        ],
                        [
                            'amount' => '2400000.00',
                            'reason' => 'Approved equipment hire REQ-DEMO-005',
                            'created_by' => $admin->id,
                            'created_at' => now()->subDays(3),
                        ],
                    );
                }

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
            $this->line('  • Inventory: cement, diesel, rebar with stock balances');
            $this->line('  • 8 flexible requisitions by different engineers:');
            $this->line('      REQ-DEMO-001 cash · approved · Joseph');
            $this->line('      REQ-DEMO-002 materials + BOQ · under review · Neema');
            $this->line('      REQ-DEMO-003 catalog cement · draft (Joseph only)');
            $this->line('      REQ-DEMO-004 labour · under review · Daniel');
            $this->line('      REQ-DEMO-005 equipment · approved · Neema');
            $this->line('      REQ-DEMO-006 transport · draft (Neema only)');
            $this->line('      REQ-DEMO-007 fuel · draft (Daniel only)');
            $this->line('      REQ-DEMO-008 new material · draft (Joseph only)');
            $this->line('  Tip: run `php artisan tenant:seed-users demo` first for multi-user logins.');
        });

        return self::SUCCESS;
    }
}
