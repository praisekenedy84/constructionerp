<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminStoreEmployeeRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\DestroyRoleRequest;
use App\Http\Requests\StoreAdminPersonRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdateMenuSettingsRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Requests\UpdateUiSettingsRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\CentralUser;
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
use Illuminate\Support\Facades\DB;
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
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function storePerson(StoreAdminPersonRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $validated = $request->validated();
        $createUser = (bool) $validated['create_user'];
        $createStaff = (bool) $validated['create_staff'];
        $actorId = $request->user()->id;
        $tenant = Tenant::findOrFail(session('tenant_id'));

        $user = null;
        $employee = null;

        try {
            if ($createUser) {
                $user = $this->authService->createUser($tenant, [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'role' => $validated['access_role'],
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
                    $actorId,
                );
            }

            if ($createStaff) {
                $payStructure = $validated['pay_structure'] instanceof \BackedEnum
                    ? $validated['pay_structure']->value
                    : $validated['pay_structure'];

                $projectIds = Employee::resolveProjectIds($validated);

                $employee = Employee::create([
                    'employee_no' => $validated['employee_no'],
                    'name' => $validated['name'],
                    'role' => $validated['job_role'],
                    'pay_structure' => $payStructure,
                    'daily_rate' => $payStructure === 'daily'
                        ? ($validated['daily_rate'] ?? null)
                        : null,
                    'monthly_salary' => $payStructure === 'monthly'
                        ? ($validated['monthly_salary'] ?? null)
                        : null,
                    'project_id' => $projectIds[0] ?? null,
                    'user_id' => $user?->id ?? ($validated['user_id'] ?? null),
                ]);

                if ($projectIds !== []) {
                    $employee->syncProjectAssignments($projectIds);
                }

                $this->auditService->write(
                    'Employee',
                    $employee->id,
                    'created',
                    null,
                    $employee->toArray(),
                    $actorId,
                );
            }
        } catch (\Throwable $e) {
            if ($user && $createStaff) {
                $this->rollbackCreatedUser($user);
            }

            throw $e;
        }

        $message = match (true) {
            $createUser && $createStaff => 'User and staff member created.',
            $createUser => 'User created.',
            default => 'Staff member added.',
        };

        return back()->with('success', $message);
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
            Employee::query()->with(['project', 'projects:id,code,name', 'user']),
            $request,
        )
            ->search(['name', 'employee_no', 'role'])
            ->dateRange('created_at')
            ->sort(['name', 'employee_no', 'role', 'created_at']);

        return Inertia::render('Admin/Staff', [
            'employees' => $listing->paginate(25),
            'filters' => $listing->filters(),
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'assignable_roles' => MenuCatalog::assignableRoles(),
            'linkable_users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function storeStaff(AdminStoreEmployeeRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $validated = $request->validated();
        $projectIds = Employee::resolveProjectIds($validated);

        $employee = Employee::create([
            ...collect($validated)->except(['project_ids'])->all(),
            'project_id' => $projectIds[0] ?? null,
        ]);

        if ($projectIds !== []) {
            $employee->syncProjectAssignments($projectIds);
        }

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
        $validated = $request->validated();
        $projectIds = Employee::resolveProjectIds($validated);

        $employee->update([
            ...collect($validated)->except(['project_ids'])->all(),
            'project_id' => $projectIds[0] ?? null,
        ]);
        $employee->syncProjectAssignments($projectIds);

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
                'order' => $navOverrides['order'] ?? MenuCatalog::keys(),
                'child_order' => $navOverrides['child_order'] ?? MenuCatalog::childKeysByParent(),
            ],
        ]);
    }

    public function updateMenu(UpdateMenuSettingsRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $existing = SystemSetting::where('key', 'ui_settings')->first();
        $current = $existing?->value ?? [];
        $existingOverrides = is_array($current['nav_overrides'] ?? null)
            ? $current['nav_overrides']
            : [];

        $validated = $request->validated();

        $current['nav_overrides'] = [
            'hidden' => $validated['hidden'] ?? [],
            'role_hidden' => $validated['role_hidden'] ?? [],
            'order' => $validated['order'] ?? ($existingOverrides['order'] ?? MenuCatalog::keys()),
            'child_order' => $validated['child_order']
                ?? ($existingOverrides['child_order'] ?? MenuCatalog::childKeysByParent()),
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
            'catalog' => ModulePermission::catalog(),
            'module_labels' => ModulePermission::moduleLabels(),
            'action_labels' => ModulePermission::actionLabels(),
            'action_descriptions' => ModulePermission::actionDescriptions(),
            'roles' => $this->formatRolesForPermissions($matrix),
            'editable_roles' => MenuCatalog::editablePermissionRoles(),
            'locked_roles' => MenuCatalog::lockedRoleNames(),
            'policy_defaults' => $matrix,
            'template_roles' => array_keys($matrix),
            'selected_role' => $request->query('role'),
        ]);
    }

    public function storeRole(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? [];

        if (! empty($validated['copy_from'])) {
            $source = Role::where('name', $validated['copy_from'])->where('guard_name', 'web')->first();
            if ($source) {
                $permissions = $source->permissions->pluck('name')->all();
            }
        }

        $role = $this->permissionService->createRole($validated['name'], $permissions);

        $this->auditService->write(
            'Role',
            $role->name,
            'role_created',
            null,
            ['permissions' => $role->permissions->pluck('name')->all()],
            $request->user()->id,
        );

        return redirect()
            ->route('admin.permissions', ['role' => $role->name])
            ->with('success', "Role “{$role->name}” created.");
    }

    public function updateRole(UpdateRoleRequest $request, string $role): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $from = urldecode($role);
        $to = $request->validated('name');

        try {
            $updated = $this->permissionService->renameRole($from, $to);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return back()->withErrors(['name' => 'Role not found.']);
        }

        $this->auditService->write(
            'Role',
            $updated->name,
            'role_renamed',
            ['name' => $from],
            ['name' => $to],
            $request->user()->id,
        );

        return redirect()
            ->route('admin.permissions', ['role' => $updated->name])
            ->with('success', "Role renamed to “{$updated->name}”.");
    }

    public function destroyRole(DestroyRoleRequest $request, string $role): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $name = urldecode($role);

        try {
            $this->permissionService->deleteRole($name);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return back()->withErrors(['role' => 'Role not found.']);
        }

        $this->auditService->write(
            'Role',
            $name,
            'role_deleted',
            ['name' => $name],
            null,
            $request->user()->id,
        );

        return redirect()
            ->route('admin.permissions')
            ->with('success', "Role “{$name}” deleted.");
    }

    public function updateRolePermissions(UpdateRolePermissionsRequest $request, string $role): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $roleName = urldecode($role);

        try {
            $this->permissionService->updateRolePermissions(
                $roleName,
                $request->validated('permissions'),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        $this->auditService->write(
            'Role',
            $roleName,
            'permissions_updated',
            null,
            ['permissions' => $request->validated('permissions')],
            $request->user()->id,
        );

        return back()->with('success', "Permissions updated for {$roleName}.");
    }

    public function syncPermissions(Request $request): RedirectResponse
    {
        $this->authorizeTenantAdmin($request->user());

        $this->permissionService->syncTenantPermissions(applyTemplateDefaults: true);

        $this->auditService->write(
            'Role',
            0,
            'permissions_synced',
            null,
            ['source' => 'policy_matrix', 'scope' => 'template_roles'],
            $request->user()->id,
        );

        return back()->with('success', 'Template role permissions reset from default policy. Custom roles were left unchanged.');
    }

    /** @param  array<string, list<string>>  $matrix */
    private function formatRolesForPermissions(array $matrix): array
    {
        $allPermissions = ModulePermission::allPermissionNames();

        return Role::where('guard_name', 'web')
            ->where('name', '!=', 'Platform Admin')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(function (Role $role) use ($matrix, $allPermissions) {
                $isLocked = MenuCatalog::isLockedRole($role->name);
                $permissions = $isLocked
                    ? $allPermissions
                    : $role->permissions->pluck('name')->sort()->values()->all();

                return [
                    'name' => $role->name,
                    'permissions' => $permissions,
                    'expected_permissions' => $matrix[$role->name] ?? [],
                    'is_locked' => $isLocked,
                    'is_editable' => ! $isLocked,
                    'user_count' => $this->permissionService->countUsersWithRole($role->name),
                ];
            })
            ->values()
            ->all();
    }

    private function authorizeTenantAdmin(User $user): void
    {
        if (! $user->canManagePlatform()) {
            abort(403, 'Tenant administration access required.');
        }
    }

    private function rollbackCreatedUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            tenancy()->central(function () use ($user): void {
                CentralUser::where('email', $user->email)->delete();
            });

            $user->delete();
        });
    }
}
