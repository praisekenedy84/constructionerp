<?php

namespace App\Services;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ListingQuery;
use App\Support\MenuCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformUserService
{
    public function update(Tenant $tenant, int $userId, array $data, int $adminId): void
    {
        if (! $tenant->isActive()) {
            throw new AuthorizationException('Users cannot be edited in a suspended company.');
        }
        tenancy()->initialize($tenant);
        try {
            $user = User::findOrFail($userId);
            abort_if($user->hasRole('Platform Admin'), 403, 'Platform accounts cannot be edited here.');
            if (! in_array($data['role'], MenuCatalog::assignableRoles(), true)) {
                throw ValidationException::withMessages(['role' => 'This role cannot be assigned.']);
            }
            if ($user->hasRole('System Administrator') && $data['role'] !== 'System Administrator'
                && User::role('System Administrator')->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Cannot remove the last System Administrator.']);
            }
            $identity = CentralUser::where('tenant_id', $tenant->id)->where('tenant_user_id', $userId)->firstOrFail();
            $username = strtolower(trim($data['username']));
            if (CentralUser::where('tenant_id', $tenant->id)->where('username', $username)->where('id', '!=', $identity->id)->exists()) {
                throw ValidationException::withMessages(['username' => 'This username is already in use in this company.']);
            }
            $access = app(CompanyAccessService::class);
            $access->central()->transaction(function () use ($data, $user, $identity, $username, $access, $tenant, $adminId) {
                DB::connection('tenant')->transaction(function () use ($data, $user, $identity, $username, $access, $tenant, $adminId) {
                    $updates = ['name' => $data['name'], 'username' => $username, 'email' => $data['email'], 'phone' => $data['phone'] ?? null];
                    if (! empty($data['password'])) {
                        $updates['password'] = Hash::make($data['password']);
                    }
                    // Platform attribution is stored centrally; do not record password hashes or a platform ID as a tenant actor.
                    $user->withoutEvents(fn () => $user->update($updates));
                    $user->syncRoles([$data['role']]);
                    $identity->update(['username' => $username, 'email' => $data['email']]);
                    $access->event('user_updated', $identity->id, $tenant->id, $adminId);
                });
            });
        } finally {
            tenancy()->end();
        }
    }

    public function create(Tenant $tenant, array $data, int $adminId): void
    {
        if (! $tenant->isActive()) {
            throw new AuthorizationException('Users cannot be created in a suspended company.');
        }
        try {
            $user = app(AuthService::class)->createUser($tenant, $data);
            $identity = CentralUser::where('tenant_id', $tenant->id)->where('tenant_user_id', $user->id)->firstOrFail();
            app(CompanyAccessService::class)->event('user_created', $identity->id, $tenant->id, $adminId);
        } finally {
            tenancy()->end();
        }
    }

    public function managementData(Tenant $tenant, Request $request): array
    {
        return [
            'users' => $this->listUsers($tenant, $request),
            'assignable_roles' => $tenant->run(fn () => MenuCatalog::assignableRoles()),
            'filters' => ListingQuery::requestFilters($request),
            'companies' => Tenant::where('id', '!=', $tenant->id)->where('status', 'active')->get(['id', 'name']),
            'company_grants' => app(CompanyAccessService::class)->central()->table('company_access_grants')
                ->join('central_users', 'central_users.id', '=', 'company_access_grants.central_user_id')
                ->where('central_users.tenant_id', $tenant->id)
                ->get(['central_users.tenant_user_id', 'company_access_grants.tenant_id']),
        ];
    }

    public function listUsers(Tenant $tenant, Request $request): LengthAwarePaginator
    {
        tenancy()->initialize($tenant);

        try {
            $listing = ListingQuery::for(User::query(), $request)
                ->search(['name', 'email', 'username', 'phone'])
                ->dateRange('created_at')
                ->sort(['name', 'email', 'created_at']);

            $paginator = $listing->paginate(25);

            $paginator->setCollection(
                $paginator->getCollection()->map(function (User $user) {
                    $user->unsetRelation('roles');

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => $user->username,
                        'phone' => $user->phone,
                        'roles' => $user->getRoleNames()->values()->all(),
                        'is_locked' => $user->isLocked(),
                        'locked_at' => $user->locked_at?->toIso8601String(),
                        'locked_reason' => $user->locked_reason,
                    ];
                }),
            );

            return $paginator;
        } finally {
            tenancy()->end();
        }
    }

    public function lock(Tenant $tenant, int $userId, ?string $reason = null): User
    {
        tenancy()->initialize($tenant);

        try {
            $user = User::findOrFail($userId);

            if ($user->hasRole('Platform Admin')) {
                throw new AuthorizationException('Cannot lock a Platform Admin user.');
            }

            $user->update([
                'locked_at' => now(),
                'locked_reason' => $reason,
            ]);

            return $user->fresh();
        } finally {
            tenancy()->end();
        }
    }

    public function unlock(Tenant $tenant, int $userId): User
    {
        tenancy()->initialize($tenant);

        try {
            $user = User::findOrFail($userId);
            $user->update([
                'locked_at' => null,
                'locked_reason' => null,
            ]);

            return $user->fresh();
        } finally {
            tenancy()->end();
        }
    }

    public function impersonate(Tenant $tenant, int $userId, int $platformAdminId): User
    {
        if ($tenant->isSuspended()) {
            throw new AuthorizationException('Cannot impersonate users in a suspended tenant.');
        }

        tenancy()->initialize($tenant);

        try {
            $target = User::findOrFail($userId);

            if ($target->isLocked()) {
                throw new AuthorizationException('Cannot impersonate a locked user.');
            }

            if ($target->hasRole('Platform Admin')) {
                throw new AuthorizationException('Cannot impersonate a Platform Admin user.');
            }

            session()->forget(['home_tenant_id', 'central_identity_id', 'company_selection_required', 'verified_company_accounts']);
            session([
                'tenant_id' => $tenant->id,
                'platform_impersonator_id' => $platformAdminId,
            ]);

            Auth::guard('web')->login($target);

            return $target;
        } finally {
            // Tenancy stays initialized for the impersonated session.
        }
    }
}
