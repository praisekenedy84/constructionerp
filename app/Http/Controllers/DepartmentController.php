<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $listing = ListingQuery::for(Department::query(), $request)
            ->search(['name', 'description'])
            ->dateRange('created_at')
            ->sort(['sort_order', 'name', 'created_at', 'is_active'], 'sort_order');

        return Inertia::render('Requisitions/Departments/Index', [
            'departments' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        Department::create($request->validated());

        return back()->with('success', 'Department created.');
    }

    public function update(UpdateDepartmentRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $department = Department::findOrFail($id);
        $department->update($request->validated());

        // Keep denormalized department labels on linked requisitions in sync.
        $department->requisitions()->update([
            'department' => $department->name,
        ]);

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $department = Department::findOrFail($id);

        if ($department->requisitions()->exists()) {
            $department->update(['is_active' => false]);

            return back()->with(
                'success',
                'Department is in use, so it was deactivated instead of deleted.',
            );
        }

        $department->delete();

        return back()->with('success', 'Department archived.');
    }
}
