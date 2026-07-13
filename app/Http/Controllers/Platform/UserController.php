<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\PlatformUserService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private PlatformUserService $platformUsers) {}

    public function index(Request $request, string $tenantId): Response
    {
        $tenant = Tenant::findOrFail($tenantId);

        return Inertia::render('Platform/Tenants/Users', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => (string) $tenant->status,
            ],
            'users' => $this->platformUsers->listUsers($tenant, $request),
            'filters' => ListingQuery::requestFilters($request),
        ]);
    }

    public function lock(Request $request, string $tenantId, int $userId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $this->platformUsers->lock($tenant, $userId, $data['reason'] ?? null);

        return back()->with('success', "User \"{$user->name}\" has been locked.");
    }

    public function unlock(string $tenantId, int $userId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $user = $this->platformUsers->unlock($tenant, $userId);

        return back()->with('success', "User \"{$user->name}\" has been unlocked.");
    }

    public function impersonate(string $tenantId, int $userId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $admin = Auth::guard('platform')->user();

        $target = $this->platformUsers->impersonate($tenant, $userId, $admin->id);

        return redirect()
            ->route('dashboard')
            ->with('success', "Now impersonating {$target->name} at {$tenant->name}.");
    }
}
