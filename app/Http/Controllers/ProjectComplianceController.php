<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectComplianceRequest;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Services\ProjectComplianceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectComplianceController extends Controller
{
    public function __construct(
        private readonly ProjectComplianceService $projectComplianceService,
    ) {}

    public function store(StoreProjectComplianceRequest $request, int $projectId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');
        $project = Project::findOrFail($projectId);

        try {
            $created = $this->projectComplianceService->attachToContract(
                $project,
                $request->validated('compliance_items'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['compliance' => $e->getMessage()]);
        }

        $count = $created->count();

        return back()->with(
            'success',
            $count === 1
                ? 'Compliance obligation attached to contract value.'
                : "{$count} compliance obligations attached to contract value.",
        );
    }

    public function destroy(Request $request, int $projectId, int $itemId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'projects', 'update');
        $project = Project::findOrFail($projectId);
        $item = ProjectComplianceItem::where('project_id', $project->id)->findOrFail($itemId);

        try {
            $this->projectComplianceService->detachFromContract($project, $item);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['compliance' => $e->getMessage()]);
        }

        return back()->with('success', 'Contract compliance obligation removed.');
    }
}
