<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantManagementService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function __construct(private TenantManagementService $tenantManagement) {}

    public function index(Request $request): Response
    {
        $listing = ListingQuery::for(Tenant::query(), $request)
            ->search(['name', 'slug'])
            ->dateRange('created_at')
            ->sort(['name', 'slug', 'status', 'created_at']);

        $tenants = $listing->paginate(25)->through(function (Tenant $tenant) {
            $stats = $this->tenantManagement->tenantStats($tenant);

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                'suspended_reason' => $tenant->suspended_reason,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'total_users' => $stats['total_users'],
                'locked_users' => $stats['locked_users'],
            ];
        });

        return Inertia::render('Platform/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => $listing->filters(),
            'statuses' => array_map(
                fn (TenantStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                TenantStatus::cases(),
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Tenants/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenant = $this->tenantManagement->provision([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'admin_name' => $data['admin_name'],
            'admin_email' => $data['admin_email'],
            'admin_password' => $data['admin_password'],
        ]);

        return redirect()
            ->route('platform.tenants.show', $tenant->id)
            ->with('success', "Tenant \"{$tenant->name}\" provisioned successfully.");
    }

    public function show(string $tenantId): Response
    {
        $tenant = Tenant::findOrFail($tenantId);
        $stats = $this->tenantManagement->tenantStats($tenant);

        return Inertia::render('Platform/Tenants/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                'suspended_reason' => $tenant->suspended_reason,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'total_users' => $stats['total_users'],
                'locked_users' => $stats['locked_users'],
            ],
        ]);
    }

    public function suspend(Request $request, string $tenantId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->isSuspended()) {
            return back()->with('error', 'Tenant is already suspended.');
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->tenantManagement->suspend($tenant, $data['reason'] ?? null);

        return back()->with('success', "Tenant \"{$tenant->name}\" has been suspended.");
    }

    public function reactivate(string $tenantId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->isActive()) {
            return back()->with('error', 'Tenant is already active.');
        }

        $this->tenantManagement->reactivate($tenant);

        return back()->with('success', "Tenant \"{$tenant->name}\" has been reactivated.");
    }
}
