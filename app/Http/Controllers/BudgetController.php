<?php

namespace App\Http\Controllers;

use App\Enums\BudgetTransactionType;
use App\Http\Requests\ManualBudgetAdjustmentRequest;
use App\Models\Project;
use App\Services\BudgetService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgetService) {}

    public function show(Request $request, int $id): Response
    {
        $this->authorizeRoles($request->user(), ['Finance Manager', 'Accountant', 'Project Manager']);

        $project = Project::findOrFail($id);
        $listing = ListingQuery::for(
            $project->budgetTransactions()->with('creator'),
            $request,
        )
            ->search(['type', 'reason', 'creator.name'])
            ->dateRange('created_at')
            ->sort(['created_at', 'amount', 'type']);

        return Inertia::render('Budgets/Show', [
            'project' => $project,
            'remaining_budget' => $this->budgetService->remainingBudget($project),
            'transactions' => $listing->paginate(50),
            'filters' => $listing->filters(),
        ]);
    }

    public function manualAdjustment(ManualBudgetAdjustmentRequest $request, int $id): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Finance Manager', 'Managing Director']);

        $this->budgetService->createTransaction($id, [
            'type' => BudgetTransactionType::ManualAdjustment,
            'amount' => $request->validated('amount'),
            'reason' => $request->validated('reason'),
            'boq_item_id' => $request->validated('boq_item_id'),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Budget adjustment recorded.');
    }
}
