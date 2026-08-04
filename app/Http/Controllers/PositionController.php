<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Position;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $listing = ListingQuery::for(Position::query(), $request)
            ->search(['name', 'description'])
            ->dateRange('created_at')
            ->sort(['sort_order', 'name', 'created_at', 'is_active'], 'sort_order');

        return Inertia::render('Requisitions/Positions/Index', [
            'positions' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        Position::create($request->validated());

        return back()->with('success', 'Position created.');
    }

    public function update(UpdatePositionRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $position = Position::findOrFail($id);
        $position->update($request->validated());

        // Keep denormalized position labels on linked requisitions in sync.
        $position->requisitions()->update([
            'recipient_position' => $position->name,
        ]);

        return back()->with('success', 'Position updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $position = Position::findOrFail($id);

        if ($position->requisitions()->exists()) {
            $position->update(['is_active' => false]);

            return back()->with(
                'success',
                'Position is in use, so it was deactivated instead of deleted.',
            );
        }

        $position->delete();

        return back()->with('success', 'Position archived.');
    }
}
