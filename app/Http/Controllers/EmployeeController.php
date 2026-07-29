<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdatePayrollEmployeeRequest;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'payroll', 'read');

        $projectId = $request->integer('project_id')
            ?: session('current_project_id')
            ?: Project::query()->orderByDesc('created_at')->value('id');

        $project = Project::findOrFail($projectId);

        $employeeListing = ListingQuery::for(
            Employee::query()->where('project_id', $project->id),
            $request,
        )
            ->search(['name', 'employee_no', 'role'])
            ->dateRange('created_at')
            ->sort(['name', 'employee_no', 'role', 'created_at']);

        return Inertia::render('Payroll/Index', [
            'project' => $project,
            'employees' => $employeeListing->paginate(25),
            'recent_runs' => PayrollRun::query()
                ->where('project_id', $project->id)
                ->withCount('items')
                ->withSum('items', 'net_pay')
                ->orderByDesc('period_end')
                ->paginate(10),
            'filters' => $employeeListing->filters(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'payroll', 'create');

        Employee::create($request->validated());

        return back()->with('success', 'Employee created.');
    }

    public function update(UpdatePayrollEmployeeRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'payroll', 'update');

        $employee = Employee::findOrFail($id);
        $employee->update($request->validated());

        return back()->with('success', 'Employee updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'payroll', 'update');

        $employee = Employee::findOrFail($id);
        $employee->delete();

        return back()->with('success', 'Employee removed.');
    }
}
