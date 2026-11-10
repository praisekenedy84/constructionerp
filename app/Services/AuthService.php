<?php

namespace App\Services;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function clearTenantSession(): void
    {
        $guard = Auth::guard('web');
        session()->forget([$guard->getName(), 'tenant_id', 'home_tenant_id', 'central_identity_id',
            'verified_company_accounts', 'company_selection_required', 'current_project_id',
            'impersonator_id', 'platform_impersonator_id', 'auth_context_version']);
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
        $guard->forgetUser();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        session()->regenerate();
    }

    public function login(string $username, string $password, bool $remember = false): User
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $login = strtolower(trim($username));
        $query = CentralUser::where('username', $login);
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $query->orWhereRaw('LOWER(email) = ?', [$login]);
        }
        $verified = [];
        $first = null;
        foreach ($query->orderBy('id')->get() as $identity) {
            $tenant = Tenant::find($identity->tenant_id);
            if (! $tenant?->isActive()) {
                continue;
            }
            $fingerprint = $tenant->run(function () use ($identity, $password) {
                $account = User::find($identity->tenant_user_id);
                if (! $account || $account->isLocked() || ! Hash::check($password, $account->password)) {
                    return null;
                }

                return hash('sha256', $account->password);
            });
            if ($fingerprint) {
                $verified[$identity->id] = $fingerprint;
                $first ??= $identity;
            }
        }
        if (! $first) {
            throw ValidationException::withMessages(['username' => ['These credentials do not match our records.']]);
        }
        // A username authenticates its own account first; matching credentials may then unlock sibling accounts.
        foreach (CentralUser::whereRaw('LOWER(email) = ?', [strtolower($first->email)])->whereNotIn('id', array_keys($verified))->get() as $identity) {
            $linkedTenant = Tenant::find($identity->tenant_id);
            if (! $linkedTenant?->isActive()) {
                continue;
            }
            $fingerprint = $linkedTenant->run(function () use ($identity, $password) {
                $account = User::find($identity->tenant_user_id);

                return $account && ! $account->isLocked() && Hash::check($password, $account->password)
                    ? hash('sha256', $account->password) : null;
            });
            if ($fingerprint) {
                $verified[$identity->id] = $fingerprint;
            }
        }
        $tenant = Tenant::findOrFail($first->tenant_id);
        tenancy()->initialize($tenant);
        $user = User::findOrFail($first->tenant_user_id);
        session()->forget(['current_project_id', 'company_selection_required', 'impersonator_id', 'platform_impersonator_id']);
        session(['tenant_id' => $tenant->id, 'home_tenant_id' => $tenant->id, 'central_identity_id' => $first->id,
            'verified_company_accounts' => $verified, 'auth_context_version' => 2]);
        Auth::guard('web')->login($user, $remember);
        if (count(app(CompanyAccessService::class)->companies()) > 1) {
            session(['company_selection_required' => true]);
        }

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

        $username = strtolower(trim($data['username'] ?? $data['email']));
        if (! preg_match('/^[a-z0-9][a-z0-9._@+\-]{2,254}$/', $username)) {
            throw ValidationException::withMessages(['username' => 'Use 3-255 letters, numbers, dots, underscores, @, + or hyphens.']);
        }
        if (CentralUser::where('tenant_id', $tenant->id)->where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => 'This username is already in use in this company.']);
        }
        $identity = CentralUser::create(['username' => $username, 'email' => $data['email'], 'tenant_id' => $tenant->id]);
        try {
            return DB::transaction(function () use ($data, $username, $identity) {
                $user = User::create(['name' => $data['name'], 'username' => $username, 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $data['password']]);
                if (! empty($data['role'])) {
                    $user->assignRole($data['role']);
                }
                $identity->update(['tenant_user_id' => $user->id]);

                return $user;
            });
        } catch (\Throwable $e) {
            $identity->delete();
            throw $e;
        }
    }

    public function initializeTenancyFromSession(): void
    {
        if (session('home_tenant_id') && session('central_identity_id')) {
            $access = app(CompanyAccessService::class);
            $id = session(Auth::guard('web')->getName());
            $user = $access->identity($id);
            if (! $user) {
                $this->clearTenantSession();

                return;
            }
            // identity() already validates the home account. Check only the selected
            // shared company instead of opening every verified account's database.
            $target = null;
            if (session('tenant_id') !== session('home_tenant_id')) {
                $target = Tenant::whereKey(session('tenant_id'))->where('status', 'active')->first();
                $granted = $target && $access->central()->table('company_access_grants')
                    ->where('central_user_id', session('central_identity_id'))
                    ->where('tenant_id', $target->id)->exists();
                if (! $granted) {
                    $target = null;
                    session(['tenant_id' => session('home_tenant_id'), 'company_selection_required' => true]);
                    session()->forget('current_project_id');
                }
            }
            $permissions = $user->modulePermissions();
            request()->attributes->set('home_permissions', $permissions);
            Auth::guard('web')->setUser($user);
            // Home tenancy is still initialized by identity(); keep its connection.
            if ($target) {
                tenancy()->end();
                tenancy()->initialize($target);
            }

            return;
        }
        $tenantId = session('tenant_id');

        if (! $tenantId || tenancy()->initialized) {
            return;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->clearTenantSession();

            return;
        }

        if ($tenant->isSuspended()) {
            $this->clearTenantSession();

            return;
        }

        tenancy()->initialize($tenant);
    }

    private function assertTenantAccessible(Tenant $tenant): void
    {
        if ($tenant->isSuspended()) {
            throw ValidationException::withMessages([
                'username' => ['This company account has been suspended. Contact your platform administrator.'],
            ]);
        }
    }
}
