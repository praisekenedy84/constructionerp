<?php

namespace App\Services;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantManagementService
{
    public function __construct(private AuthService $authService) {}

    /**
     * @param  array{name: string, slug: string, admin_name: string, admin_email: string, admin_password: string}  $data
     */
    public function provision(array $data): Tenant
    {
        $slug = Str::slug($data['slug']);

        if (Tenant::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['A tenant with this slug already exists.'],
            ]);
        }

        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => $slug,
            'status' => TenantStatus::Active->value,
        ]);

        tenancy()->initialize($tenant);

        $this->authService->createUser($tenant, [
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        return $tenant;
    }

    public function suspend(Tenant $tenant, ?string $reason = null): Tenant
    {
        $tenant->update([
            'status' => TenantStatus::Suspended->value,
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ]);

        return $tenant->fresh();
    }

    public function reactivate(Tenant $tenant): Tenant
    {
        $tenant->update([
            'status' => TenantStatus::Active->value,
            'suspended_at' => null,
            'suspended_reason' => null,
        ]);

        return $tenant->fresh();
    }

    public function assertTenantAccessible(Tenant $tenant): void
    {
        if ($tenant->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => ['This company account has been suspended. Contact your platform administrator.'],
            ]);
        }
    }

    /** @return array{total_users: int, locked_users: int} */
    public function tenantStats(Tenant $tenant): array
    {
        tenancy()->initialize($tenant);

        try {
            return [
                'total_users' => User::count(),
                'locked_users' => User::whereNotNull('locked_at')->count(),
            ];
        } finally {
            tenancy()->end();
        }
    }
}
