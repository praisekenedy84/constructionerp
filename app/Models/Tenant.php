<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection', config('database.default'));
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'status',
            'suspended_at',
            'suspended_reason',
        ];
    }

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function statusEnum(): TenantStatus
    {
        return TenantStatus::tryFrom($this->status) ?? TenantStatus::Active;
    }

    public function isActive(): bool
    {
        return $this->statusEnum() === TenantStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->statusEnum() === TenantStatus::Suspended;
    }
}
