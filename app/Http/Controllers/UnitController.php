<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $listing = ListingQuery::for(Unit::query(), $request)
            ->search(['name', 'description'])
            ->dateRange('created_at')
            ->sort(['sort_order', 'name', 'created_at', 'is_active'], 'sort_order');

        return Inertia::render('Requisitions/Units/Index', [
            'units' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        Unit::create($request->validated());

        return back()->with('success', 'Unit created.');
    }

    public function update(UpdateUnitRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $unit = Unit::findOrFail($id);
        $previousName = $unit->name;
        $unit->update($request->validated());

        // Keep denormalized unit labels on requisition lines in sync.
        if (strcasecmp($previousName, $unit->name) !== 0) {
            RequisitionItem::query()
                ->whereRaw('LOWER(TRIM(unit)) = ?', [mb_strtolower(trim($previousName))])
                ->update(['unit' => $unit->name]);
        }

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $unit = Unit::findOrFail($id);

        if ($unit->isUsedOnRequisitionItems()) {
            $unit->update(['is_active' => false]);

            return back()->with(
                'success',
                'Unit is in use, so it was deactivated instead of deleted.',
            );
        }

        $unit->delete();

        return back()->with('success', 'Unit archived.');
    }
}
