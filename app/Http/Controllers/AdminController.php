<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminStoreEmployeeRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdateMenuSettingsRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Requests\UpdateUiSettingsRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Employee;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Services\UserManagementService;
use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use App\Support\ListingQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private PermissionService $permissionService,
        private UserManagementService $userManagement,
        private AuditService $auditService,
    ) {}

    public function users(Request $request): Response
    {
        $this->authorizeTenantAdmin($request->user());

        $listing = ListingQuery::for(User::query(), $request)
            ->search(['name', 'email'])
            ->dateRange('created_at')
            ->sort(['name', 'email', 'created_at']);

        $users = $listing->paginate(25);

        $users->getCollection()->transform(function (User $user) use ($request) {
            $user->unsetRelation('roles');
            $user->setAttribute('roles', $user->getRoleNames()->values()->all());
            $user->setAttribute('is_self', $user->id === $request->user()->id);

            return $user;
        });

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $listing->filters(),
            'assignable_roles' => MenuCatalog::assignableRoles(),
        ]);
    }

    public function createUser(CreateUserRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $tenantId = session('tenant_id');
        $tenant = Tenant::findOrFail($tenantId);

        $user = $this->authService->createUser($tenant, [
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'role' => $request->validated('role'),
        ]);

        $this->auditService->write(
            'User',
            $user->id,
            'created',
            null,
            [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
            ],
            $request->user()->id,
        );

        return back()->with('success', 'User created.');
    }

    public function updateUser(UpdateUserRequest $request, int $id): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $user = User::findOrFail($id);

        try {
            $this->userManagement->update($request->user(), $user, $request->validated());
        } catch (AuthorizationException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('success', 'User updated.');
    }

    public function deleteUser(Request $request, int $id): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $user = User::findOrFail($id);

        try {
            $this->userManagement->delete($request->user(), $user);
        } catch (AuthorizationException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', 'User removed from your organization.');
    }

    public function staff(Request $request): Response
    {
        $this->authorizeTenantAdmin($request->user());

        $listing = ListingQuery::for(
            Employee::query()->with(['project', 'user']),
            $request,
        )
            ->search(['name', 'employee_no', 'role'])
            ->dateRange('created_at')
            ->sort(['name', 'employee_no', 'role', 'created_at']);

        return Inertia::render('Admin/Staff', [
            'employees' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'linkable_users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function storeStaff(AdminStoreEmployeeRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $employee = Employee::create($request->validated());

        $this->auditService->write(
            'Employee',
            $employee->id,
            'created',
            null,
            $employee->toArray(),
            $request->user()->id,
        );

        return back()->with('success', 'Staff member added.');
    }

    public function updateStaff(UpdateEmployeeRequest $request, int $id): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $employee = Employee::findOrFail($id);
        $before = $employee->toArray();
        $employee->update($request->validated());

        $this->auditService->write(
            'Employee',
            $employee->id,
            'updated',
            $before,
            $employee->fresh()->toArray(),
            $request->user()->id,
        );

        return back()->with('success', 'Staff member updated.');
    }

    public function deleteStaff(Request $request, int $id): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $employee = Employee::findOrFail($id);

        $this->auditService->write(
            'Employee',
            $employee->id,
            'deleted',
            $employee->toArray(),
            null,
            $request->user()->id,
        );

        $employee->delete();

        return back()->with('success', 'Staff member removed.');
    }

    public function updateUI(UpdateUiSettingsRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $existing = SystemSetting::where('key', 'ui_settings')->first();
        $current = $existing?->value ?? [];

        SystemSetting::updateOrCreate(
            ['key' => 'ui_settings'],
            [
                'value' => array_merge($current, $request->validated()),
                'updated_at' => now(),
            ],
        );

        return back()->with('success', 'UI settings updated.');
    }

    public function menu(Request $request): Response
    {
        $this->authorizeTenantAdmin($request->user());

        $setting = SystemSetting::where('key', 'ui_settings')->first();
        $uiSettings = $setting?->value ?? [];
        $navOverrides = $uiSettings['nav_overrides'] ?? [];

        return Inertia::render('Admin/Menu', [
            'menu_catalog' => MenuCatalog::items(),
            'roles' => MenuCatalog::tenantRoles(),
            'nav_overrides' => [
                'hidden' => $navOverrides['hidden'] ?? [],
                'role_hidden' => $navOverrides['role_hidden'] ?? [],
            ],
        ]);
    }

    public function updateMenu(UpdateMenuSettingsRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $existing = SystemSetting::where('key', 'ui_settings')->first();
        $current = $existing?->value ?? [];

        $current['nav_overrides'] = [
            'hidden' => $request->validated('hidden') ?? [],
            'role_hidden' => $request->validated('role_hidden') ?? [],
        ];

        SystemSetting::updateOrCreate(
            ['key' => 'ui_settings'],
            [
                'value' => $current,
                'updated_at' => now(),
            ],
        );

        return back()->with('success', 'Menu configuration saved.');
    }

    public function permissions(Request $request): Response
    {
        $this->authorizeTenantAdmin($request->user());

        $matrix = ModulePermission::matrix();

        return Inertia::render('Admin/Permissions', [
            'modules' => ModulePermission::MODULES,
            'actions' => ModulePermission::ACTIONS,
            'roles' => $this->formatRolesForPermissions($matrix),
            'editable_roles' => MenuCatalog::editablePermissionRoles(),
            'policy_defaults' => $matrix,
        ]);
    }

    public function updateRolePermissions(UpdateRolePermissionsRequest $request, string $role): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        try {
            $this->permissionService->updateRolePermissions(
                $role,
                $request->validated('permissions'),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        $this->auditService->write(
            'Role',
            $role,
            'permissions_updated',
            null,
            ['permissions' => $request->validated('permissions')],
            $request->user()->id,
        );

        return back()->with('success', "Permissions updated for {$role}.");
    }

    public function syncPermissions(Request $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $this->permissionService->syncTenantPermissions();

        $this->auditService->write(
            'Role',
            0,
            'permissions_synced',
            null,
            ['source' => 'policy_matrix'],
            $request->user()->id,
        );

        return back()->with('success', 'Role permissions reset from default policy.');
    }

    /** @param  array<string, list<string>>  $matrix */
    private function formatRolesForPermissions(array $matrix): array
    {
        return Role::where('guard_name', 'web')
            ->whereIn('name', array_keys($matrix))
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                'expected_permissions' => $matrix[$role->name] ?? [],
            ])
            ->values()
            ->all();
    }

    private function authorizeTenantAdmin(User $user): void
    {
        if (! $user->canManagePlatform()) {
            abort(403, 'Tenant administration access required.');
        }
    }
}
