<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\CashAllocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(private CashAllocationService $cashService) {}

    public function dashboard(Request $request, int $projectId): Response
    {
        $this->authorizeRoles($request->user(), ['Finance Manager', 'Accountant', 'Manager']);

        $project = Project::findOrFail($projectId);

        $dashboard = $this->cashService->dashboard($project);

        return Inertia::render('Finance/Index', [
            'project' => $project,
            'reconciliation' => $dashboard['reconciliation'],
            'recent_allocations' => $dashboard['recent_allocations'],
        ]);
    }
}
