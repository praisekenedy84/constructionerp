<?php

namespace App\Http\Controllers;

use App\Enums\ValuationStatus;
use App\Http\Requests\StoreValuationRequest;
use App\Http\Requests\UpdateValuationRequest;
use App\Models\ComplianceRule;
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
        $this->authorizePermission($request->user(), 'valuations', 'read');

        $project = Project::findOrFail($id);

        $listing = ListingQuery::for(
            $project->valuations()->with('deductions'),
            $request,
        )
            ->search(['status'])
            ->dateRange('created_at')
            ->sort(['certificate_no', 'total_deductions', 'created_at'], 'certificate_no', 'desc');

        $totalCompliance = bcadd((string) $project->valuations()->sum('total_deductions'), '0', 2);
        $netProjectAmount = bcsub(bcadd((string) $project->contract_amount, '0', 2), $totalCompliance, 2);
        if (bccomp($netProjectAmount, '0', 2) === -1) {
            $netProjectAmount = '0.00';
        }

        return Inertia::render('Projects/Valuations/Index', [
            'project' => $project,
            'valuations' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'summary' => [
                'contract_amount' => (string) $project->contract_amount,
                'total_compliance' => $totalCompliance,
                'net_project_amount' => $netProjectAmount,
            ],
        ]);
    }

    public function create(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'valuations', 'create');

        $project = Project::findOrFail($id);
        $otherCompliance = bcadd((string) $project->valuations()->sum('total_deductions'), '0', 2);
        $nextNo = (int) $project->valuations()->max('certificate_no') + 1;

        return Inertia::render('Valuations/Create', [
            'project' => $project,
            'next_certificate_no' => $nextNo,
            'other_ipcs_compliance_total' => $otherCompliance,
            'available_rules' => $this->availableRules(),
        ]);
    }

    public function store(StoreValuationRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'valuations', 'create');

        $project = Project::findOrFail($id);
        $valuation = $this->valuationService->create(
            $project,
            $request->validated('compliance_items') ?? [],
            $request->user(),
        );

        return redirect()
            ->route('projects.valuations.show', [$project->id, $valuation->id])
            ->with('success', "IPC-{$valuation->certificate_no} draft created.");
    }

    public function show(Request $request, int $id, int $valuationId): Response
    {
        $this->authorizePermission($request->user(), 'valuations', 'read');

        $project = Project::findOrFail($id);
        $valuation = $project->valuations()->with(['deductions', 'creator', 'certifier'])->findOrFail($valuationId);

        $totalCompliance = bcadd((string) $project->valuations()->sum('total_deductions'), '0', 2);
        $netProjectAmount = bcsub(bcadd((string) $project->contract_amount, '0', 2), $totalCompliance, 2);
        if (bccomp($netProjectAmount, '0', 2) === -1) {
            $netProjectAmount = '0.00';
        }

        return Inertia::render('Valuations/Show', [
            'project' => $project,
            'valuation' => $valuation,
            'summary' => [
                'contract_amount' => (string) $project->contract_amount,
                'total_compliance' => $totalCompliance,
                'net_project_amount' => $netProjectAmount,
            ],
        ]);
    }

    public function edit(Request $request, int $id, int $valuationId): Response
    {
        $this->authorizePermission($request->user(), 'valuations', 'update');

        $project = Project::findOrFail($id);
        $valuation = $project->valuations()->with('deductions')->findOrFail($valuationId);

        if ($valuation->status !== ValuationStatus::Draft) {
            abort(403, 'Only draft IPCs can be edited.');
        }

        $otherCompliance = bcadd(
            (string) $project->valuations()->where('id', '!=', $valuation->id)->sum('total_deductions'),
            '0',
            2,
        );

        $attachedRuleIds = $valuation->deductions
            ->pluck('compliance_rule_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        return Inertia::render('Valuations/Edit', [
            'project' => $project,
            'valuation' => $valuation,
            'other_ipcs_compliance_total' => $otherCompliance,
            'available_rules' => $this->availableRules($attachedRuleIds),
        ]);
    }

    public function update(UpdateValuationRequest $request, int $id, int $valuationId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'valuations', 'update');

        $project = Project::findOrFail($id);
        $valuation = $project->valuations()->findOrFail($valuationId);

        try {
            $this->valuationService->updateDraft(
                $valuation,
                $request->validated('compliance_items') ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('projects.valuations.show', [$project->id, $valuation->id])
            ->with('success', "IPC-{$valuation->certificate_no} updated. Net budget recalculated.");
    }

    public function destroy(Request $request, int $id, int $valuationId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'valuations', 'delete-soft');

        $project = Project::findOrFail($id);
        $valuation = $project->valuations()->findOrFail($valuationId);
        $certificateNo = $valuation->certificate_no;

        try {
            $this->valuationService->deleteDraft($valuation);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('projects.valuations.index', $project->id)
            ->with('success', "IPC-{$certificateNo} archived. Net budget recalculated.");
    }

    public function certify(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'valuations', 'approve');

        $valuation = Valuation::findOrFail($id);
        $this->valuationService->certify($valuation, $request->user());

        return back()->with('success', 'IPC certified.');
    }

    /**
     * @param  list<int>  $includeIds
     * @return list<array{id: int, name: string, description: string|null}>
     */
    private function availableRules(array $includeIds = []): array
    {
        return ComplianceRule::query()
            ->where(function ($query) use ($includeIds) {
                $query->where('is_active', true);
                if ($includeIds !== []) {
                    $query->orWhereIn('id', $includeIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (ComplianceRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'description' => $rule->description,
            ])
            ->values()
            ->all();
    }
}
