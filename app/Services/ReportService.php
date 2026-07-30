<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Enums\FulfillmentType;
use App\Enums\ProjectStatus;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionStatus;
use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\EquipmentAssignment;
use App\Models\Expense;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\ReportSchedule;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportService
{
    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    public function build(string $slug, array $filters = []): array
    {
        return match ($slug) {
            'executive_dashboard' => $this->executiveDashboard($filters),
            'project_profitability' => $this->projectProfitability($filters),
            'budget_utilization' => $this->budgetUtilization($filters),
            'cash_position' => $this->cashPosition($filters),
            'boq_dashboard' => $this->boqDashboard($filters),
            'requisition_pipeline' => $this->requisitionPipeline($filters),
            'inventory_valuation' => $this->inventoryValuation($filters),
            'payroll_summary' => $this->payrollSummary($filters),
            'equipment_utilization' => $this->equipmentUtilization($filters),
            'audit_trail' => $this->auditTrail($filters),
            default => throw new \InvalidArgumentException("Unknown report slug: {$slug}"),
        };
    }

    public function executiveDashboard(array $filters = []): array
    {
        $projects = Project::query()
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->get();

        $projectSummaries = $projects->map(function (Project $project) {
            $spent = (string) BudgetTransaction::where('project_id', $project->id)->sum('amount');

            return [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status->value,
                'net_budget' => (string) $project->net_budget,
                'remaining_budget' => $this->budgetService->remainingBudget($project),
                'spent' => $spent,
                'utilization_pct' => bccomp((string) $project->net_budget, '0', 2) === 0
                    ? '0'
                    : bcmul(bcdiv($spent, (string) $project->net_budget, 4), '100', 2),
                'physical_progress_pct' => (string) $project->physical_progress_pct,
            ];
        });

        $cashOnHand = $this->sumCashBalances($filters['project_id'] ?? null);
        $pendingApprovals = ApprovalStep::where('status', ApprovalStepStatus::Pending)->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'projects' => $projectSummaries->values()->all(),
            'totals' => [
                'project_count' => $projects->count(),
                'total_net_budget' => $this->sumDecimal($projectSummaries->pluck('net_budget')->all()),
                'total_remaining' => $this->sumDecimal($projectSummaries->pluck('remaining_budget')->all()),
                'cash_on_hand' => $cashOnHand,
                'pending_approvals' => $pendingApprovals,
            ],
        ];
    }

    public function dashboardStats(array $filters = []): array
    {
        $executive = $this->executiveDashboard($filters);
        $activeProjects = collect($executive['projects'])
            ->where('status', ProjectStatus::Active->value);

        $totalNetBudget = $this->sumDecimal($activeProjects->pluck('net_budget')->all());
        $totalSpent = $this->sumDecimal($activeProjects->pluck('spent')->all());
        $budgetUtilization = bccomp($totalNetBudget, '0', 2) === 0
            ? 0.0
            : (float) bcmul(bcdiv($totalSpent, $totalNetBudget, 4), '100', 2);

        $openRequisitions = Requisition::query()
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->whereNotIn('status', [
                RequisitionStatus::Fulfilled,
                RequisitionStatus::Closed,
                RequisitionStatus::Cancelled,
                RequisitionStatus::Rejected,
            ])
            ->count();

        return [
            'active_projects' => $activeProjects->count(),
            'total_projects' => (int) $executive['totals']['project_count'],
            'pending_approvals' => (int) $executive['totals']['pending_approvals'],
            'budget_utilization' => $budgetUtilization,
            'cash_on_hand' => (string) $executive['totals']['cash_on_hand'],
            'open_requisitions' => $openRequisitions,
        ];
    }

    public function dashboardCharts(array $filters = []): array
    {
        $executive = $this->executiveDashboard($filters);
        $pipeline = $this->requisitionPipeline($filters);

        $projectBudget = collect($executive['projects'])
            ->where('status', ProjectStatus::Active->value)
            ->sortByDesc(fn (array $project) => (float) $project['utilization_pct'])
            ->take(8)
            ->map(fn (array $project) => [
                'name' => $project['code'],
                'spent' => (float) $project['spent'],
                'remaining' => (float) $project['remaining_budget'],
                'utilization' => (float) $project['utilization_pct'],
            ])
            ->values()
            ->all();

        $requisitionStatus = collect($pipeline['by_status'])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->map(fn (array $row) => [
                'name' => ucwords(str_replace('_', ' ', $row['status'])),
                'count' => $row['count'],
                'amount' => (float) $row['total_amount'],
            ])
            ->values()
            ->all();

        return [
            'project_budget' => $projectBudget,
            'requisition_status' => $requisitionStatus,
        ];
    }

    public function projectProfitability(array $filters = []): array
    {
        $projects = Project::query()
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'projects' => $projects->map(function (Project $project) {
                $costs = (string) BudgetTransaction::where('project_id', $project->id)->sum('amount');
                $contract = (string) $project->contract_amount;
                $margin = bcsub($contract, $costs, 2);
                $marginPct = bccomp($contract, '0', 2) === 0
                    ? '0'
                    : bcmul(bcdiv($margin, $contract, 4), '100', 2);

                return [
                    'project_id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'contract_amount' => $contract,
                    'total_cost' => $costs,
                    'margin' => $margin,
                    'margin_pct' => $marginPct,
                ];
            })->values()->all(),
        ];
    }

    public function budgetUtilization(array $filters = []): array
    {
        $projectId = $filters['project_id'] ?? null;

        $items = BoqItem::query()
            ->whereHas('section', function ($query) use ($projectId) {
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
            })
            ->with('section.project')
            ->get();

        $byCategory = [];

        foreach ($items as $item) {
            $category = $item->category->value;
            $budgeted = (string) $item->budgeted_amount;
            $consumed = bcmul((string) $item->consumed_qty, (string) $item->unit_rate, 2);
            $reserved = bcmul((string) $item->reserved_qty, (string) $item->unit_rate, 2);

            if (! isset($byCategory[$category])) {
                $byCategory[$category] = [
                    'category' => $category,
                    'budgeted' => '0',
                    'consumed' => '0',
                    'reserved' => '0',
                    'available' => '0',
                ];
            }

            $byCategory[$category]['budgeted'] = bcadd($byCategory[$category]['budgeted'], $budgeted, 2);
            $byCategory[$category]['consumed'] = bcadd($byCategory[$category]['consumed'], $consumed, 2);
            $byCategory[$category]['reserved'] = bcadd($byCategory[$category]['reserved'], $reserved, 2);
            $byCategory[$category]['available'] = bcadd(
                $byCategory[$category]['available'],
                bcmul((string) $item->available_qty, (string) $item->unit_rate, 2),
                2
            );
        }

        foreach ($byCategory as &$row) {
            $row['utilization_pct'] = bccomp($row['budgeted'], '0', 2) === 0
                ? '0'
                : bcmul(bcdiv($row['consumed'], $row['budgeted'], 4), '100', 2);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'project_id' => $projectId,
            'categories' => array_values($byCategory),
        ];
    }

    public function cashPosition(array $filters = []): array
    {
        $scope = $filters['scope'] ?? null;
        $projectId = $filters['project_id'] ?? null;

        // Explicit organization scope (do not treat missing project_id as "org only").
        $organizationOnly = $scope === 'organization'
            || ($filters['organization'] ?? false) === true;

        $allocations = CashAllocation::query()
            ->when($organizationOnly, fn ($q) => $q->whereNull('project_id'))
            ->when(! $organizationOnly && $projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('status', CashAllocationStatus::Received)
            ->get();

        $received = $this->sumDecimal($allocations->pluck('received_amount')->map(fn ($v) => (string) $v)->all());
        $utilized = $this->sumDecimal($allocations->pluck('utilized_amount')->map(fn ($v) => (string) $v)->all());
        $balance = bcsub($received, $utilized, 2);

        $committedReqs = Requisition::query()
            ->when($organizationOnly, fn ($q) => $q->whereRaw('0 = 1'))
            ->when(! $organizationOnly && $projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('status', [
                RequisitionStatus::Approved,
                RequisitionStatus::Amended,
            ])
            ->where(function ($query) {
                $query->where('addressed_to', RequisitionAddressedTo::Finance->value)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('addressed_to')
                            ->whereIn('fulfillment_type', [
                                FulfillmentType::CashDisbursement->value,
                                FulfillmentType::DirectSupplierPayment->value,
                            ]);
                    });
            })
            ->get();

        $committed = '0';
        foreach ($committedReqs as $requisition) {
            $committed = bcadd($committed, (string) ($requisition->amended_amount ?? $requisition->original_amount), 2);
        }

        $disbursed = (string) CashDisbursement::query()
            ->when($organizationOnly, function ($q) {
                $q->whereHas('cashAllocation', fn ($aq) => $aq->whereNull('project_id'));
            })
            ->when(! $organizationOnly && $projectId, function ($q) use ($projectId) {
                $q->where(function ($inner) use ($projectId) {
                    $inner->whereHas('requisition', fn ($rq) => $rq->where('project_id', $projectId))
                        ->orWhereHas('expense', fn ($eq) => $eq->where('project_id', $projectId));
                });
            })
            ->sum('amount');

        // Outstanding compares like with like: only payments already made against
        // requisitions that are still committed. Fulfilled ones leave both sides.
        $paidAgainstCommitted = '0.00';
        if ($committedReqs->isNotEmpty()) {
            $paidAgainstCommitted = (string) CashDisbursement::query()
                ->whereIn('requisition_id', $committedReqs->modelKeys())
                ->sum('amount');
        }

        $outstanding = bcsub($committed, $paidAgainstCommitted, 2);

        return [
            'generated_at' => now()->toIso8601String(),
            'scope' => $organizationOnly ? 'organization' : ($projectId ? 'project' : 'all'),
            'project_id' => $organizationOnly ? null : $projectId,
            'received' => $received,
            'utilized' => $utilized,
            'cash_on_hand' => $balance,
            'committed' => bcadd((string) $committed, '0', 2),
            'disbursed' => bcadd($disbursed, '0', 2),
            'outstanding' => $outstanding,
            'allocations' => $allocations->map(fn (CashAllocation $a) => [
                'id' => $a->id,
                'project_id' => $a->project_id,
                'received_amount' => (string) $a->received_amount,
                'utilized_amount' => (string) $a->utilized_amount,
                'balance' => $a->balance,
            ])->values()->all(),
        ];
    }

    public function boqDashboard(array $filters = []): array
    {
        $projectId = $filters['project_id'] ?? null;

        $items = BoqItem::query()
            ->whereHas('section', function ($query) use ($projectId) {
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
            })
            ->get();

        $categories = [];

        foreach ($items as $item) {
            $category = $item->category->value;

            if (! isset($categories[$category])) {
                $categories[$category] = [
                    'category' => $category,
                    'budgeted_qty' => '0',
                    'consumed_qty' => '0',
                    'reserved_qty' => '0',
                    'available_qty' => '0',
                    'budgeted_amount' => '0',
                ];
            }

            $categories[$category]['budgeted_qty'] = bcadd($categories[$category]['budgeted_qty'], (string) $item->budgeted_qty, 4);
            $categories[$category]['consumed_qty'] = bcadd($categories[$category]['consumed_qty'], (string) $item->consumed_qty, 4);
            $categories[$category]['reserved_qty'] = bcadd($categories[$category]['reserved_qty'], (string) $item->reserved_qty, 4);
            $categories[$category]['available_qty'] = bcadd($categories[$category]['available_qty'], (string) $item->available_qty, 4);
            $categories[$category]['budgeted_amount'] = bcadd($categories[$category]['budgeted_amount'], (string) $item->budgeted_amount, 2);
        }

        foreach ($categories as &$row) {
            $row['utilization_pct'] = bccomp($row['budgeted_qty'], '0', 4) === 0
                ? '0'
                : bcmul(bcdiv($row['consumed_qty'], $row['budgeted_qty'], 4), '100', 2);
            $row['variance_qty'] = bcsub($row['budgeted_qty'], bcadd($row['consumed_qty'], $row['reserved_qty'], 4), 4);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'project_id' => $projectId,
            'categories' => array_values($categories),
        ];
    }

    public function requisitionPipeline(array $filters = []): array
    {
        $query = Requisition::query()
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['department'] ?? null, fn ($q, $dept) => $q->where('department', $dept));

        $byStatus = $query->clone()
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(COALESCE(amended_amount, original_amount)) as total_amount'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status instanceof RequisitionStatus ? $row->status->value : $row->status,
                'count' => (int) $row->count,
                'total_amount' => bcadd((string) ($row->total_amount ?? '0'), '0', 2),
            ])
            ->values()
            ->all();

        $byDepartment = $query->clone()
            ->select('department', DB::raw('COUNT(*) as count'))
            ->groupBy('department')
            ->get()
            ->map(fn ($row) => [
                'department' => $row->department,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'by_status' => $byStatus,
            'by_department' => $byDepartment,
        ];
    }

    public function inventoryValuation(array $filters = []): array
    {
        $balances = StockBalance::query()
            ->with(['inventoryItem', 'stockLocation'])
            ->when($filters['project_id'] ?? null, function ($q, $projectId) {
                $q->whereHas('stockLocation', fn ($sq) => $sq->where('project_id', $projectId));
            })
            ->get();

        $lines = $balances->map(function (StockBalance $balance) {
            $value = bcmul((string) $balance->quantity_on_hand, (string) $balance->average_cost, 2);

            return [
                'inventory_item_id' => $balance->inventory_item_id,
                'item_code' => $balance->inventoryItem?->code,
                'item_name' => $balance->inventoryItem?->name,
                'stock_location_id' => $balance->stock_location_id,
                'location_name' => $balance->stockLocation?->name,
                'quantity_on_hand' => (string) $balance->quantity_on_hand,
                'average_cost' => (string) $balance->average_cost,
                'total_value' => $value,
            ];
        });

        return [
            'generated_at' => now()->toIso8601String(),
            'lines' => $lines->values()->all(),
            'total_value' => $this->sumDecimal($lines->pluck('total_value')->all()),
        ];
    }

    public function payrollSummary(array $filters = []): array
    {
        $projectId = $filters['project_id'] ?? null;

        $runs = PayrollRun::query()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->with('items.employee')
            ->orderByDesc('period_end')
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'runs' => $runs->map(function (PayrollRun $run) {
                $totalNet = (string) $run->items->sum('net_pay');

                return [
                    'id' => $run->id,
                    'project_id' => $run->project_id,
                    'period_start' => $run->period_start->toDateString(),
                    'period_end' => $run->period_end->toDateString(),
                    'status' => $run->status->value,
                    'employee_count' => $run->items->count(),
                    'total_net_pay' => bcadd($totalNet, '0', 2),
                    'items' => $run->items->map(fn (PayrollItem $item) => [
                        'employee_no' => $item->employee?->employee_no,
                        'employee_name' => $item->employee?->name,
                        'base' => (string) $item->base,
                        'deductions_total' => (string) $item->deductions_total,
                        'net_pay' => (string) $item->net_pay,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    public function equipmentUtilization(array $filters = []): array
    {
        $assignments = EquipmentAssignment::query()
            ->with(['equipment', 'project'])
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'assignments' => $assignments->map(function (EquipmentAssignment $assignment) {
                $budgeted = (string) ($assignment->hours_budgeted ?? '0');
                $used = (string) $assignment->hours_used;
                $utilizationPct = bccomp($budgeted, '0', 2) === 0
                    ? '0'
                    : bcmul(bcdiv($used, $budgeted, 4), '100', 2);

                return [
                    'assignment_id' => $assignment->id,
                    'equipment_id' => $assignment->equipment_id,
                    'equipment_name' => $assignment->equipment?->name,
                    'project_id' => $assignment->project_id,
                    'project_name' => $assignment->project?->name,
                    'hours_budgeted' => $budgeted,
                    'hours_used' => $used,
                    'utilization_pct' => $utilizationPct,
                    'start_date' => $assignment->start_date?->toDateString(),
                    'end_date' => $assignment->end_date?->toDateString(),
                ];
            })->values()->all(),
        ];
    }

    public function auditTrail(array $filters = []): array
    {
        $logs = AuditLog::query()
            ->with('performer')
            ->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($filters['entity_id'] ?? null, fn ($q, $id) => $q->where('entity_id', $id))
            ->when($filters['performed_by'] ?? null, fn ($q, $id) => $q->where('performed_by', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->limit($filters['limit'] ?? 500)
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'entries' => $logs->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'action' => $log->action,
                'performed_by' => $log->performed_by,
                'performer_name' => $log->performer?->name,
                'before_data' => $log->before_data,
                'after_data' => $log->after_data,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function runDueSchedules(): void
    {
        $schedules = ReportSchedule::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('last_run_at')
                    ->orWhere('last_run_at', '<', now()->subHour());
            })
            ->get();

        foreach ($schedules as $schedule) {
            $this->build($schedule->report_slug, $schedule->parameters ?? []);

            $schedule->update(['last_run_at' => now()]);

            \Illuminate\Support\Facades\Log::info('Report schedule executed', [
                'schedule_id' => $schedule->id,
                'report_slug' => $schedule->report_slug,
                'recipients' => $schedule->recipients,
            ]);
        }
    }

    /**
     * @return array<int, array{slug: string, name: string, category: string, description: string}>
     */
    public function catalog(): array
    {
        return [
            ['slug' => 'executive_dashboard', 'name' => 'Executive Dashboard', 'category' => 'Executive', 'description' => 'Portfolio summary across all projects.'],
            ['slug' => 'project_profitability', 'name' => 'Project Profitability', 'category' => 'Finance', 'description' => 'Contract margin by project.'],
            ['slug' => 'budget_utilization', 'name' => 'Budget Utilization', 'category' => 'Finance', 'description' => 'BOQ category spend vs budget.'],
            ['slug' => 'cash_position', 'name' => 'Cash Position', 'category' => 'Finance', 'description' => 'Cash on hand and outstanding commitments.'],
            ['slug' => 'boq_dashboard', 'name' => 'BOQ Dashboard', 'category' => 'Projects', 'description' => 'Quantity utilization by BOQ category.'],
            ['slug' => 'requisition_pipeline', 'name' => 'Requisition Pipeline', 'category' => 'Procurement', 'description' => 'Requisitions grouped by status and department.'],
            ['slug' => 'inventory_valuation', 'name' => 'Inventory Valuation', 'category' => 'Inventory', 'description' => 'Stock value by item and location.'],
            ['slug' => 'payroll_summary', 'name' => 'Payroll Summary', 'category' => 'Payroll', 'description' => 'Payroll runs and net pay totals.'],
            ['slug' => 'equipment_utilization', 'name' => 'Equipment Utilization', 'category' => 'Equipment', 'description' => 'Hours used vs budgeted by assignment.'],
            ['slug' => 'audit_trail', 'name' => 'Audit Trail', 'category' => 'Compliance', 'description' => 'System mutation log export.'],
        ];
    }

    public function preview(string $slug, array $filters = []): array
    {
        $slug = str_replace('-', '_', $slug);
        $built = $this->build($slug, $filters);
        $meta = collect($this->catalog())->firstWhere('slug', $slug) ?? [
            'slug' => $slug,
            'name' => ucwords(str_replace('_', ' ', $slug)),
            'category' => 'Reports',
            'description' => '',
        ];

        [$data, $columns] = $this->tabularize($slug, $built);

        return [
            ...$meta,
            'data' => $data,
            'columns' => $columns,
        ];
    }

    public function export(string $slug, array $filters, string $format): StreamedResponse
    {
        $preview = $this->preview($slug, $filters);
        $filename = str_replace('_', '-', $preview['slug']).'.csv';

        return response()->streamDownload(function () use ($preview) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($preview['columns'], 'label'));

            foreach ($preview['data'] as $row) {
                fputcsv($out, array_map(
                    fn ($col) => $row[$col['key']] ?? '',
                    $preview['columns'],
                ));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ReportSchedule>
     */
    public function schedules(): \Illuminate\Database\Eloquent\Collection
    {
        return ReportSchedule::query()->orderByDesc('created_at')->get();
    }

    public function createSchedule(array $data, User $user): ReportSchedule
    {
        $recipients = is_string($data['recipients'] ?? null)
            ? array_values(array_filter(array_map('trim', explode(',', $data['recipients']))))
            : ($data['recipients'] ?? []);

        return ReportSchedule::create([
            'report_slug' => str_replace('-', '_', $data['report_slug']),
            'project_id' => $data['project_id'] ?? null,
            'frequency' => $data['frequency'],
            'recipients' => $recipients,
            'parameters' => $data['parameters'] ?? [],
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{key: string, label: string}>}
     */
    private function tabularize(string $slug, array $built): array
    {
        $rows = match ($slug) {
            'executive_dashboard' => $built['projects'] ?? [],
            'project_profitability' => $built['projects'] ?? [],
            'budget_utilization' => $built['categories'] ?? [],
            'cash_position' => $built['allocations'] ?? [],
            'boq_dashboard' => $built['categories'] ?? [],
            'requisition_pipeline' => array_merge($built['by_status'] ?? [], $built['by_department'] ?? []),
            'inventory_valuation' => $built['lines'] ?? [],
            'payroll_summary' => $built['runs'] ?? [],
            'equipment_utilization' => $built['assignments'] ?? [],
            'audit_trail' => $built['entries'] ?? [],
            default => [],
        };

        if ($rows === []) {
            return [[], []];
        }

        $first = (array) ($rows[0] ?? []);
        $columns = array_map(
            fn ($key) => ['key' => $key, 'label' => ucwords(str_replace('_', ' ', $key))],
            array_keys($first),
        );

        $data = array_map(function ($row) use ($columns) {
            $normalized = [];

            foreach ($columns as $column) {
                $value = is_array($row) ? ($row[$column['key']] ?? null) : ($row->{$column['key']} ?? null);
                $normalized[$column['key']] = is_scalar($value) || $value === null
                    ? ($value ?? '')
                    : json_encode($value);
            }

            return $normalized;
        }, $rows);

        return [$data, $columns];
    }

    private function sumCashBalances(?int $projectId): string
    {
        $allocations = CashAllocation::query()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('status', CashAllocationStatus::Received)
            ->get();

        $total = '0';

        foreach ($allocations as $allocation) {
            $total = bcadd($total, $allocation->balance, 2);
        }

        return $total;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function sumDecimal(array $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, (string) $value, 2);
        }

        return $total;
    }
}
