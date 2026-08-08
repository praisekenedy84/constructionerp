<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhaseRetentionActionRequest;
use App\Http\Requests\StoreProjectPhaseRequest;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Services\PhaseService;
use App\Services\ProjectComplianceService;
use App\Services\SaleService;
use App\Services\ValuationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectPhaseController extends Controller
{
    public function __construct(
        private readonly PhaseService $phaseService,
        private readonly ValuationService $valuationService,
        private readonly ProjectComplianceService $projectComplianceService,
        private readonly SaleService $saleService,
    ) {}

    public function show(Request $request, int $projectId, int $phaseId): Response
    {
        $this->authorizePermission($request->user(), 'projects', 'read');

        $project = Project::findOrFail($projectId);
        $phase = ProjectPhase::where('project_id', $project->id)->findOrFail($phaseId);
        $sale = $this->saleService->ensureForPhase($phase);
        $valuations = $phase->valuations()
            ->with('deductions')
            ->orderBy('certificate_no')
            ->get();

        $totalCompliance = bcadd((string) $valuations->sum('total_deductions'), '0', 2);

        return Inertia::render('Projects/Phases/Show', [
            'project' => $project,
            'phase' => $phase,
            'sale' => $this->saleService->formatSale($sale),
            'valuations' => $valuations,
            'summary' => [
                'contract_amount' => (string) $project->contract_amount,
                'phase_compliance_total' => $totalCompliance,
                'project_net_budget' => (string) $project->net_budget,
            ],
        ]);
    }

    public function store(StoreProjectPhaseRequest $request, int $projectId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');
        $project = Project::findOrFail($projectId);
        $validated = $request->validated();
        $ipcs = $validated['ipcs'] ?? [];
        unset($validated['ipcs']);

        try {
            $migratedCount = 0;
            $phase = DB::transaction(function () use ($request, $project, $validated, $ipcs, &$migratedCount) {
                $phase = $this->phaseService->create($project, $validated);

                // Phase One initiation: move contract-level compliance onto this phase (same amounts).
                if ((int) $phase->sequence_no === 1) {
                    $valuation = $this->projectComplianceService->migrateContractItemsToPhase(
                        $project->fresh(),
                        $phase,
                        $request->user(),
                    );
                    $migratedCount = $valuation?->deductions()?->count() ?? 0;
                }

                foreach ($ipcs as $ipc) {
                    $this->valuationService->create(
                        $project,
                        $phase,
                        $ipc['compliance_items'] ?? [],
                        $request->user(),
                    );
                }

                return $phase->fresh();
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['phase' => $e->getMessage()]);
        }

        $ipcCount = count($ipcs);
        $message = match (true) {
            $migratedCount > 0 && $ipcCount > 0 => "Phase {$phase->sequence_no} added. Migrated {$migratedCount} contract compliance item(s) and added {$ipcCount} IPC".($ipcCount === 1 ? '' : 's').'.',
            $migratedCount > 0 => "Phase {$phase->sequence_no} added. Migrated {$migratedCount} contract compliance item(s) to this phase.",
            $ipcCount > 0 => "Phase {$phase->sequence_no} added with {$ipcCount} IPC".($ipcCount === 1 ? '' : 's').'.',
            default => "Phase {$phase->sequence_no} added and budget updated.",
        };

        return back()->with('success', $message);
    }

    public function releaseRetention(StorePhaseRetentionActionRequest $request, int $projectId, int $phaseId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');
        $project = Project::findOrFail($projectId);
        $phase = ProjectPhase::where('project_id', $project->id)->findOrFail($phaseId);

        try {
            $this->phaseService->releaseRetention($phase);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['phase' => $e->getMessage()]);
        }

        return back()->with('success', 'Retention released into project budget.');
    }

    public function forfeitRetention(StorePhaseRetentionActionRequest $request, int $projectId, int $phaseId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');
        $project = Project::findOrFail($projectId);
        $phase = ProjectPhase::where('project_id', $project->id)->findOrFail($phaseId);

        try {
            $this->phaseService->forfeitRetention($phase);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['phase' => $e->getMessage()]);
        }

        return back()->with('success', 'Retention forfeited and excluded from budget.');
    }

    public function close(Request $request, int $projectId, int $phaseId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');
        $project = Project::findOrFail($projectId);
        $phase = ProjectPhase::where('project_id', $project->id)->findOrFail($phaseId);

        try {
            $this->phaseService->close($phase);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['phase' => $e->getMessage()]);
        }

        return back()->with('success', 'Phase closed. Its profit share can now be converted to a receivable.');
    }
}
