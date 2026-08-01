<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectProgressRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\ComplianceRule;
use App\Models\Project;
use App\Services\BudgetService;
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

        return Inertia::render('Projects/Create', [
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

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'create');

        $validated = $request->validated();
        $ipcs = $validated['ipcs'] ?? [];
        unset($validated['ipcs']);

        $project = DB::transaction(function () use ($request, $validated, $ipcs) {
            $project = $this->persistProject(new Project(), $validated);

            foreach ($ipcs as $ipc) {
                $this->valuationService->create(
                    $project,
                    $ipc['compliance_items'] ?? [],
                    $request->user(),
                );
            }

            return $project->fresh();
        });

        session(['current_project_id' => $project->id]);

        $ipcCount = count($ipcs);
        $message = $ipcCount > 0
            ? "Project created with {$ipcCount} IPC".($ipcCount === 1 ? '' : 's').'.'
            : 'Project created successfully.';

        return redirect()
            ->route('projects.show', $project->id)
            ->with('success', $message);
    }

    public function show(Request $request, int $id): Response
    {
        $project = Project::with(['withholdingTaxRates'])->findOrFail($id);

        return Inertia::render('Projects/Show', [
            'project' => $this->withBudgetSummary($project),
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
            // Rate-% rules are based on contract; recalculate IPC amounts when it changes.
            $this->valuationService->recalculateProjectIpcs($project->fresh());
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

    private function withBudgetSummary(Project $project): Project
    {
        $remainingBudget = $this->budgetService->remainingBudget($project);
        $profitPercentage = bccomp((string) $project->net_budget, '0', 2) === 0
            ? '0.00'
            : bcmul(bcdiv($remainingBudget, (string) $project->net_budget, 4), '100', 2);

        $project->setAttribute('remaining_budget', $remainingBudget);
        $project->setAttribute('profit_percentage', $profitPercentage);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistProject(Project $project, array $attributes): Project
    {
        // Net budget starts as the full contract; IPC compliance deductions adjust it later.
        if (! $project->exists) {
            $attributes['net_budget'] = bcadd((string) $attributes['contract_amount'], '0', 2);
        }

        $project->fill($attributes)->save();

        return $project->refresh();
    }
}
