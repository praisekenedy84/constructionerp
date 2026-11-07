<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\PlatformUserService;
use App\Support\MenuCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
            ...$this->platformUsers->managementData($tenant, $request),
        ]);
    }

    public function store(Request $request, string $tenantId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        abort_unless($tenant->isActive(), 403, 'Users cannot be created in a suspended company.');
        $roles = $tenant->run(fn () => MenuCatalog::assignableRoles());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._@+\-]+$/'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in($roles)],
        ]);
        $this->platformUsers->create($tenant, $data, Auth::guard('platform')->id());

        return redirect()->route('platform.tenants.show', $tenantId)->with('success', 'User created.');
    }

    public function companyAccess(Request $request, string $tenantId, int $userId): RedirectResponse
    {
        $data = $request->validate(['tenant_ids' => ['present', 'array'], 'tenant_ids.*' => ['string', 'distinct']]);
        $home = Tenant::findOrFail($tenantId);
        $home->run(fn () => User::findOrFail($userId));
        $identity = CentralUser::where('tenant_id', $tenantId)->where('tenant_user_id', $userId)->firstOrFail();
        foreach ($data['tenant_ids'] as $target) {
            abort_if($target === $tenantId || ! Tenant::whereKey($target)->where('status', 'active')->exists(), 422);
        }
        $access = app(CompanyAccessService::class);
        abort_if($data['tenant_ids'] && ! $access->central()->table('company_access_events')->where('action', 'attachments_protected')->exists(), 422, 'Run company-access:protect-attachments before granting access.');
        $access->central()->transaction(function () use ($access, $data, $identity) {
            $old = $access->central()->table('company_access_grants')->where('central_user_id', $identity->id)->pluck('tenant_id')->all();
            foreach (array_diff($old, $data['tenant_ids']) as $target) {
                $access->central()->table('company_access_grants')->where('central_user_id', $identity->id)->where('tenant_id', $target)->delete();
                $access->event('revoked', $identity->id, $target, Auth::guard('platform')->id());
            }
            foreach (array_diff($data['tenant_ids'], $old) as $target) {
                $access->central()->table('company_access_grants')->insert(['central_user_id' => $identity->id, 'tenant_id' => $target, 'granted_by' => Auth::guard('platform')->id(), 'created_at' => now(), 'updated_at' => now()]);
                $access->event('granted', $identity->id, $target, Auth::guard('platform')->id());
            }
        });

        return back()->with('success', 'Company access updated.');
    }

    public function update(Request $request, string $tenantId, int $userId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        abort_unless($tenant->isActive(), 403, 'Users cannot be edited in a suspended company.');
        $roles = $tenant->run(fn () => MenuCatalog::assignableRoles());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._@+\-]+$/'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in($roles)],
        ]);
        $this->platformUsers->update($tenant, $userId, $data, Auth::guard('platform')->id());

        return back()->with('success', 'User updated.');
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
