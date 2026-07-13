<?php

namespace App\Auth;

use App\Services\AuthService;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;

class TenantAwareUserProvider extends EloquentUserProvider
{
    public function __construct(
        Hasher $hasher,
        string $model,
        private readonly AuthService $authService,
    ) {
        parent::__construct($hasher, $model);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        if (! $this->ensureTenancy()) {
            return null;
        }

        return parent::retrieveById($identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        if (! $this->ensureTenancy()) {
            return null;
        }

        return parent::retrieveByToken($identifier, $token);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        // Login is handled by AuthService — never resolve credentials on central DB.
        return null;
    }

    private function ensureTenancy(): bool
    {
        if (tenancy()->initialized) {
            return true;
        }

        if (! session()->has('tenant_id')) {
            return false;
        }

        $this->authService->initializeTenancyFromSession();

        return tenancy()->initialized;
    }
}
