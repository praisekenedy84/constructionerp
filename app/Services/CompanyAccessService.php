<?php

namespace App\Services;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class CompanyAccessService
{
    public static function visiting(): bool
    {
        return session('home_tenant_id') && session('home_tenant_id') !== session('tenant_id');
    }

    public function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    public function accountAvailable(CentralUser $identity): bool
    {
        $verified = session('verified_company_accounts');
        if ($verified === null) {
            return (int) $identity->id === (int) session('central_identity_id');
        }
        $fingerprint = $verified[$identity->id] ?? null;
        $tenant = Tenant::find($identity->tenant_id);
        if (! $fingerprint || ! $tenant?->isActive()) {
            return false;
        }

        return $tenant->run(function () use ($identity, $fingerprint) {
            $user = User::find($identity->tenant_user_id);

            return $user && ! $user->isLocked() && hash_equals($fingerprint, hash('sha256', $user->password));
        });
    }

    public function companies(): array
    {
        $ids = array_keys(session('verified_company_accounts', [session('central_identity_id') => null]));
        $choices = [];
        foreach (CentralUser::whereIn('id', $ids)->get() as $identity) {
            $home = Tenant::find($identity->tenant_id);
            if (! $home?->isActive() || ! $this->accountAvailable($identity)) {
                continue;
            }
            $choices[] = ['id' => $home->id, 'name' => $home->name, 'read_only' => false,
                'identity_id' => $identity->id, 'username' => $identity->username];
            $targets = $this->central()->table('company_access_grants')->where('central_user_id', $identity->id)->pluck('tenant_id');
            foreach (Tenant::whereIn('id', $targets)->where('status', 'active')->get() as $target) {
                if ($target->id === $home->id) {
                    continue;
                }
                $choices[] = ['id' => $target->id, 'name' => $target->name, 'read_only' => true,
                    'identity_id' => $identity->id, 'username' => $identity->username];
            }
        }

        return $choices;
    }

    public function identity($id): ?User
    {
        $home = Tenant::find(session('home_tenant_id'));
        $identity = CentralUser::find(session('central_identity_id'));
        if (! $home?->isActive() || ! $identity || $identity->tenant_id !== $home->id) {
            return null;
        }
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($home);
        config(['database.connections.company_identity' => DB::connection('tenant')->getConfig()]);
        DB::purge('company_identity');
        $user = User::on('company_identity')->find($id);
        if (! $user || $user->isLocked() || (int) $user->id !== (int) $identity->tenant_user_id) {
            return null;
        }
        $verified = session('verified_company_accounts');
        if ($verified !== null && (! isset($verified[$identity->id]) || ! hash_equals($verified[$identity->id], hash('sha256', $user->password)))) {
            return null;
        }
        $user->load('roles.permissions', 'permissions');

        return $user;
    }

    public function event(string $action, ?int $identity, string $tenant, ?int $admin = null): void
    {
        $this->central()->table('company_access_events')->insert([
            'action' => $action, 'central_user_id' => $identity, 'tenant_id' => $tenant,
            'platform_admin_id' => $admin, 'created_at' => now(),
        ]);
    }
}
