<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreValuationRequest;
use App\Models\Project;
use App\Models\Valuation;
use App\Services\ValuationService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ValuationController extends Controller
{
    public function __construct(private ValuationService $valuationService) {}

    public function index(Request $request, int $id): Response
    {
        $this->authorizeRoles($request->user(), ['Quantity Surveyor', 'Finance Manager', 'Project Manager']);

        $project = Project::findOrFail($id);

        $listing = ListingQuery::for(
            $project->valuations()->with('deductions'),
            $request,
        )
            ->search(['status'])
            ->dateRange('created_at')
            ->sort(['certificate_no', 'gross_value', 'net_value', 'created_at'], 'certificate_no', 'desc');

        return Inertia::render('Projects/Valuations/Index', [
            'project' => $project,
            'valuations' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function create(Request $request, int $id): Response
    {
        $this->authorizeRoles($request->user(), ['Quantity Surveyor']);

        $project = Project::with('complianceRules')->findOrFail($id);

        return Inertia::render('Valuations/Create', [
            'project' => $project,
            'preview_deductions' => [],
        ]);
    }

    public function store(StoreValuationRequest $request, int $id): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Quantity Surveyor']);

        $project = Project::findOrFail($id);
        $valuation = $this->valuationService->create($project, $request->validated('gross_value'), $request->user());

        return back()->with('success', "Valuation certificate #{$valuation->certificate_no} draft created.");
    }

    public function certify(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Managing Director', 'Finance Manager']);

        $valuation = Valuation::findOrFail($id);
        $this->valuationService->certify($valuation, $request->user());

        return back()->with('success', 'Valuation certified.');
    }
}
