<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectProgressRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\ComplianceRule;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Services\BudgetService;
use App\Services\PhaseService;
use App\Services\ProjectComplianceService;
use App\Services\SaleService;
use App\Services\ValuationService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private BudgetService $budgetService,
        private ValuationService $valuationService,
        private PhaseService $phaseService,
        private SaleService $saleService,
        private ProjectComplianceService $projectComplianceService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'read');

        $listing = ListingQuery::for(Project::query(), $request)
            ->search(['name', 'code', 'client'])
            ->dateRange('created_at')
            ->sort(['name', 'code', 'client', 'status', 'net_budget', 'created_at']);

        $projects = $listing->paginate(ListingQuery::PER_PAGE);
        $projects->getCollection()->transform(fn (Project $project) => $this->withBudgetSummary($project));

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => $listing->filters(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'create');

        return Inertia::render('Projects/Create');
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'create');

        $validated = $request->validated();
        $ipcs = $validated['ipcs'] ?? [];
        $initialPhaseName = $validated['initial_phase_name'] ?? 'Phase 1';
        $initialPhaseDisbursed = $validated['initial_phase_disbursed_amount'] ?? null;
        unset($validated['ipcs'], $validated['initial_phase_name'], $validated['initial_phase_disbursed_amount']);

        $project = DB::transaction(function () use ($request, $validated, $ipcs, $initialPhaseName, $initialPhaseDisbursed) {
            $project = $this->persistProject(new Project, $validated);
            $phase = null;

            $shouldCreatePhase = ($initialPhaseDisbursed !== null && $initialPhaseDisbursed !== '')
                || $ipcs !== [];

            if ($shouldCreatePhase) {
                $phase = $this->phaseService->create($project, [
                    'name' => $initialPhaseName ?: 'Phase 1',
                    'disbursed_amount' => (string) ($initialPhaseDisbursed ?: $project->contract_amount),
                ]);
            }

            foreach ($ipcs as $ipc) {
                $this->valuationService->create(
                    $project,
                    $phase,
                    $ipc['compliance_items'] ?? [],
                    $request->user(),
                );
            }

            if ($phase === null) {
                // Contract is the first financial source: remaining = contract − contract compliance.
                $this->budgetService->syncProjectNetBudget($project);
            }

            $this->saleService->ensureForProject($project);

            return $project->fresh();
        });

        session(['current_project_id' => $project->id]);

        $ipcCount = count($ipcs);
        $message = match (true) {
            $ipcCount > 0 => "Project created with Phase 1 and {$ipcCount} IPC".($ipcCount === 1 ? '' : 's').'.',
            $initialPhaseDisbursed !== null && $initialPhaseDisbursed !== '' => 'Project created with Phase 1 disbursement.',
            default => 'Project created successfully.',
        };

        return redirect()
            ->route('projects.show', $project->id)
            ->with('success', $message);
    }

    public function show(Request $request, int $id): Response
    {
        $project = Project::with(['withholdingTaxRates', 'sale'])->findOrFail($id);
        $sale = $this->saleService->ensureForProject($project);
        $budget = $this->budgetService->summary($project);

        $complianceItems = ProjectComplianceItem::query()
            ->where('project_id', $project->id)
            ->with(['complianceRule:id,name', 'phase:id,sequence_no,name', 'events'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProjectComplianceItem $item) => [
                'id' => $item->id,
                'compliance_rule_id' => $item->compliance_rule_id,
                'rule_name' => $item->complianceRule?->name,
                'calculation_type' => $item->calculation_type->value,
                'rate' => $item->rate !== null ? (string) $item->rate : null,
                'fixed_amount' => $item->fixed_amount !== null ? (string) $item->fixed_amount : null,
                'amount' => (string) $item->amount,
                'allocation_level' => $item->allocation_level->value,
                'phase_id' => $item->phase_id,
                'phase_label' => $item->phase
                    ? 'Phase '.$item->phase->sequence_no.': '.$item->phase->name
                    : null,
                'valuation_id' => $item->valuation_id,
                'attached_at' => $item->attached_at?->toIso8601String(),
                'migrated_at' => $item->migrated_at?->toIso8601String(),
                'events' => $item->events->map(fn ($event) => [
                    'event_type' => $event->event_type->value,
                    'phase_id' => $event->phase_id,
                    'created_at' => $event->created_at?->toIso8601String(),
                    'meta' => $event->meta,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Projects/Show', [
            'project' => $this->withBudgetSummary($project, $budget),
            'sale' => $this->saleService->formatSale($sale),
            'phases' => $project->phases()
                ->withCount('valuations')
                ->withSum('valuations', 'total_deductions')
                ->orderBy('sequence_no')
                ->get(),
            'compliance_items' => $complianceItems,
            'contract_summary' => [
                'contract_amount' => $budget['contract_amount'],
                'compliance_total' => $budget['contract_compliance_total'],
                'remaining_contract_value' => $budget['remaining_contract_value'],
                'phase_allocated' => $budget['phase_allocated'],
                'unallocated_contract_value' => $budget['unallocated_contract_value'],
                'has_phases' => $budget['has_phases'],
            ],
            'available_rules' => ComplianceRule::active()
                ->orderBy('name')
                ->get(['id', 'name', 'description'])
                ->map(fn (ComplianceRule $rule) => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'description' => $rule->description,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'update');

        $project = Project::findOrFail($id);

        return Inertia::render('Projects/Edit', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => $project->client,
                'location' => $project->location,
                'contract_amount' => (string) $project->contract_amount,
                'wht_percentage' => (string) $project->wht_percentage,
                'start_date' => $project->start_date?->format('Y-m-d') ?? '',
                'end_date' => $project->end_date?->format('Y-m-d') ?? '',
                'status' => $project->status?->value ?? 'planning',
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');

        $project = Project::findOrFail($id);

        DB::transaction(function () use ($request, $project) {
            $this->persistProject($project, $request->validated());
            $fresh = $project->fresh();
            $this->projectComplianceService->recalculateContractItems($fresh);
            // Rate-% IPC rules are based on phase disbursement; still recalculate if project setup changes.
            $this->valuationService->recalculateProjectIpcs($fresh);
        });

        return redirect()
            ->route('projects.show', $project->id)
            ->with('success', 'Project updated successfully.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'delete-soft');

        $project = Project::findOrFail($id);
        $project->delete();

        if ((int) session('current_project_id') === $id) {
            session()->forget('current_project_id');
        }

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project archived.');
    }

    public function select(Request $request, int $id): RedirectResponse
    {
        Project::findOrFail($id);
        session(['current_project_id' => $id]);

        return back()->with('success', 'Active project updated.');
    }

    public function updateProgress(UpdateProjectProgressRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');

        $project = Project::findOrFail($id);
        $project->update($request->validated());

        return back()->with('success', 'Project progress updated.');
    }

    private function withBudgetSummary(Project $project, ?array $budget = null): Project
    {
        $budget ??= $this->budgetService->summary($project);
        $remainingBudget = $budget['remaining_budget'];
        $profitPercentage = bccomp((string) $project->net_budget, '0', 2) === 0
            ? '0.00'
            : bcmul(bcdiv($remainingBudget, (string) $project->net_budget, 4), '100', 2);

        $project->setAttribute('remaining_budget', $remainingBudget);
        $project->setAttribute('profit_percentage', $profitPercentage);
        $project->setAttribute('utilization_percentage', $budget['utilization_percentage']);
        $project->setAttribute('remaining_contract_value', $budget['remaining_contract_value']);
        $project->setAttribute('contract_compliance_total', $budget['contract_compliance_total']);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistProject(Project $project, array $attributes): Project
    {
        if (! empty($attributes['client'])) {
            $attributes['customer_id'] = Customer::firstOrCreate([
                'name' => trim((string) $attributes['client']),
            ])->id;
        }

        // Before phases: net_budget tracks remaining contract value (synced after save).
        // After phases: net_budget is the sum of phase nets.
        if (! $project->exists) {
            $attributes['net_budget'] = '0.00';
        }

        $project->fill($attributes)->save();

        return $project->refresh();
    }
}
