<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectProgressRequest;
use App\Models\Project;
use App\Models\ProjectComplianceRule;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'read');

        $listing = ListingQuery::for(Project::query(), $request)
            ->search(['name', 'code', 'client'])
            ->dateRange('created_at')
            ->sort(['name', 'code', 'client', 'status', 'net_budget', 'created_at']);

        return Inertia::render('Projects/Index', [
            'projects' => $listing->paginate(20),
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
            $project = Project::create($request->safe()->except('compliance_rules'));

            foreach ($request->input('compliance_rules', []) as $rule) {
                ProjectComplianceRule::create([
                    'project_id' => $project->id,
                    ...$rule,
                ]);
            }

            return $project;
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
            'project' => $project,
        ]);
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
}
