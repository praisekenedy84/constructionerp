<?php

namespace App\Http\Controllers;

use App\Enums\ComplianceRuleType;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectProgressRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\ProjectComplianceRule;
use App\Services\BudgetService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private BudgetService $budgetService) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'read');

        $listing = ListingQuery::for(Project::query(), $request)
            ->search(['name', 'code', 'client'])
            ->dateRange('created_at')
            ->sort(['name', 'code', 'client', 'status', 'net_budget', 'created_at']);

        $projects = $listing->paginate(20);
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

        $project = DB::transaction(function () use ($request) {
            return $this->persistProject(new Project(), $request->safe()->except('compliance_rules'), $request->validated('compliance_rules') ?? []);
        });

        session(['current_project_id' => $project->id]);

        return redirect()
            ->route('projects.show', $project->id)
            ->with('success', 'Project created successfully.');
    }

    public function show(Request $request, int $id): Response
    {
        $project = Project::with(['complianceRules', 'withholdingTaxRates'])->findOrFail($id);

        return Inertia::render('Projects/Show', [
            'project' => $this->withBudgetSummary($project),
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'update');

        $project = Project::with('complianceRules')->findOrFail($id);

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
                'compliance_rules' => $project->complianceRules->map(fn (ProjectComplianceRule $rule) => [
                    'rule_type' => $rule->rule_type->value,
                    'rate' => (string) $rule->rate,
                    'is_active' => (bool) $rule->is_active,
                    'max_amount' => $rule->max_amount !== null ? (string) $rule->max_amount : '',
                ])->values()->all(),
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');

        $project = Project::findOrFail($id);

        DB::transaction(function () use ($request, $project) {
            $this->persistProject(
                $project,
                $request->safe()->except('compliance_rules'),
                $request->validated('compliance_rules') ?? [],
            );
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
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function persistProject(Project $project, array $attributes, array $rules): Project
    {
        $contractAmount = (string) $attributes['contract_amount'];
        $attributes['net_budget'] = Project::netBudgetFromCharges($contractAmount, $rules);
        $attributes['wht_percentage'] = self::whtPercentageFromRules($rules, $attributes['wht_percentage'] ?? 0);

        $project->fill($attributes)->save();

        $project->complianceRules()->delete();

        foreach ($rules as $rule) {
            ProjectComplianceRule::create([
                'project_id' => $project->id,
                'rule_type' => $rule['rule_type'],
                'rate' => $rule['rate'],
                'is_active' => true,
                'max_amount' => $rule['max_amount'] ?? null,
            ]);
        }

        return $project->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private static function whtPercentageFromRules(array $rules, mixed $fallback = 0): string
    {
        foreach ($rules as $rule) {
            $type = $rule['rule_type'] ?? null;
            $value = $type instanceof ComplianceRuleType ? $type->value : (string) $type;

            if ($value !== 'wht') {
                continue;
            }

            $rate = $rule['rate'] ?? null;

            return is_numeric($rate) && (float) $rate > 0 ? (string) $rate : '0';
        }

        return is_numeric($fallback) ? (string) $fallback : '0';
    }
}
