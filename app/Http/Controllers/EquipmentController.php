<?php

namespace App\Http\Controllers;

use App\Http\Requests\EquipmentAssignRequest;
use App\Http\Requests\EquipmentFuelRequest;
use App\Http\Requests\EquipmentMaintenanceRequest;
use App\Http\Requests\StoreEquipmentRequest;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentFuelLog;
use App\Models\EquipmentMaintenance;
use App\Models\Project;
use App\Services\EquipmentService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentController extends Controller
{
    public function __construct(private EquipmentService $equipmentService) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'equipment', 'read');

        $listing = ListingQuery::for(
            Equipment::query()->with(['assignments.project']),
            $request,
        )
            ->search(['name', 'type'])
            ->dateRange('created_at')
            ->sort(['name', 'type', 'status', 'created_at']);

        return Inertia::render('Equipment/Index', [
            'equipment' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function assignments(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'equipment', 'read');

        $listing = ListingQuery::for(
            EquipmentAssignment::query()->with(['equipment', 'project']),
            $request,
        )
            ->search(['equipment.name', 'project.name'])
            ->dateRange('start_date')
            ->sort(['start_date', 'end_date', 'created_at'], 'start_date');

        return Inertia::render('Equipment/Assignments', [
            'assignments' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'equipment' => Equipment::orderBy('name')->get(),
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function maintenanceIndex(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'equipment', 'read');

        $listing = ListingQuery::for(
            EquipmentMaintenance::query()->with(['equipment', 'purchaseOrder']),
            $request,
        )
            ->search(['description', 'equipment.name', 'purchaseOrder.purchase_order_no'])
            ->dateRange('date')
            ->sort(['date', 'cost', 'created_at'], 'date');

        return Inertia::render('Equipment/Maintenance', [
            'maintenances' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'equipment' => Equipment::orderBy('name')->get(),
        ]);
    }

    public function fuelIndex(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'equipment', 'read');

        $listing = ListingQuery::for(
            EquipmentFuelLog::query()->with('equipment'),
            $request,
        )
            ->search(['equipment.name'])
            ->dateRange('date')
            ->sort(['date', 'liters', 'cost', 'created_at'], 'date');

        return Inertia::render('Equipment/Fuel', [
            'fuel_logs' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'equipment' => Equipment::orderBy('name')->get(),
        ]);
    }

    public function store(StoreEquipmentRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'equipment', 'create');

        Equipment::create($request->validated());

        return back()->with('success', 'Equipment registered.');
    }

    public function assign(EquipmentAssignRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'equipment', 'assign');

        return back()->with('success', 'Equipment assigned to project.');
    }

    public function maintenance(EquipmentMaintenanceRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'equipment', 'update');

        $equipment = Equipment::findOrFail($request->validated('equipment_id'));
        $this->equipmentService->logMaintenance($equipment, $request->validated(), $request->user());

        return back()->with('success', 'Maintenance logged.');
    }

    public function fuel(EquipmentFuelRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'equipment', 'update');

        $equipment = Equipment::findOrFail($request->validated('equipment_id'));
        $this->equipmentService->logFuel($equipment, $request->validated(), $request->user());

        return back()->with('success', 'Fuel log recorded.');
    }
}
