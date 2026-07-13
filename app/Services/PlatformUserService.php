<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Support\ListingQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class PlatformUserService
{
    public function listUsers(Tenant $tenant, Request $request): LengthAwarePaginator
    {
        tenancy()->initialize($tenant);

        try {
            $listing = ListingQuery::for(User::query(), $request)
                ->search(['name', 'email'])
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
