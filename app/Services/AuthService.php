<?php

namespace App\Services;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function login(string $email, string $password, bool $remember = false): User
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $centralUser = CentralUser::where('email', $email)->first();

        if (! $centralUser) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $tenant = Tenant::find($centralUser->tenant_id);

        if (! $tenant) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $this->assertTenantAccessible($tenant);

        tenancy()->initialize($tenant);

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            tenancy()->end();

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->isLocked()) {
            tenancy()->end();

            throw ValidationException::withMessages([
                'email' => ['Your account has been locked. Contact your administrator.'],
            ]);
        }

        session(['tenant_id' => $tenant->id]);

        Auth::login($user, $remember);

        return $user;
    }

    public function logout(): void
    {
        Auth::logout();
        session()->forget('tenant_id');
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    /**
     * @param  array{name: string, email: string, password: string, role?: string}  $data
     */
    public function createUser(Tenant $tenant, array $data): User
    {
        tenancy()->initialize($tenant);

        try {
            tenancy()->central(function () use ($tenant, $data) {
                CentralUser::create([
                    'email' => $data['email'],
                    'tenant_id' => $tenant->id,
                ]);
            });

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            if (! empty($data['role'])) {
                $user->assignRole($data['role']);
            }

            return $user;
        } catch (\Throwable $e) {
            tenancy()->central(function () use ($data) {
                CentralUser::where('email', $data['email'])->delete();
            });

            throw $e;
        }
    }

    public function initializeTenancyFromSession(): void
    {
        $tenantId = session('tenant_id');

        if (! $tenantId || tenancy()->initialized) {
            return;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            session()->forget(['tenant_id', 'impersonator_id', 'platform_impersonator_id']);
            Auth::guard('web')->logout();

            return;
        }

        if ($tenant->isSuspended()) {
            session()->forget(['tenant_id', 'impersonator_id', 'platform_impersonator_id']);
            Auth::guard('web')->logout();

            return;
        }

        tenancy()->initialize($tenant);
    }

    private function assertTenantAccessible(Tenant $tenant): void
    {
        if ($tenant->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => ['This company account has been suspended. Contact your platform administrator.'],
            ]);
        }
    }
}
