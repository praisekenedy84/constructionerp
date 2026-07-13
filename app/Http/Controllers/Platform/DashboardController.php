<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $tenants = Tenant::query()->orderBy('name')->get();

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'total_tenants' => $tenants->count(),
                'active_tenants' => $tenants->where('status', TenantStatus::Active->value)->count(),
                'suspended_tenants' => $tenants->where('status', TenantStatus::Suspended->value)->count(),
            ],
            'recent_tenants' => $tenants->take(5)->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                'created_at' => $tenant->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
