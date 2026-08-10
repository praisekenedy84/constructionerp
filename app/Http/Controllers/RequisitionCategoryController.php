<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRequisitionCategoryRequest;
use App\Http\Requests\UpdateRequisitionCategoryRequest;
use App\Models\RequisitionCategory;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $query = RequisitionCategory::query();

        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->string('expense_type')->toString());
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['name', 'description'])
            ->dateRange('created_at')
            ->sort(['sort_order', 'name', 'expense_type', 'created_at', 'is_active'], 'sort_order');

        return Inertia::render('Requisitions/Categories/Index', [
            'categories' => $listing->paginate(25),
            'filters' => $listing->filters([
                'expense_type' => $request->input('expense_type'),
            ]),
        ]);
    }

    public function store(StoreRequisitionCategoryRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        RequisitionCategory::create($request->validated());

        return back()->with('success', 'Requisition category created.');
    }

    public function update(UpdateRequisitionCategoryRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $category = RequisitionCategory::findOrFail($id);
        $category->update($request->validated());

        return back()->with('success', 'Requisition category updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $category = RequisitionCategory::findOrFail($id);

        if ($category->requisitions()->exists() || $category->primaryRequisitions()->exists()) {
            $category->update(['is_active' => false]);

            return back()->with(
                'success',
                'Category is in use, so it was deactivated instead of deleted.',
            );
        }

        $category->delete();

        return back()->with('success', 'Requisition category archived.');
    }
}
