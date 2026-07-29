<?php

namespace App\Models;

use App\Support\ModulePermission;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locked_at',
        'locked_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isSuperUser(): bool
    {
        return $this->hasRole([
            'Platform Admin',
            'System Administrator',
            'Managing Director',
        ]);
    }

    public function isSystemAdministrator(): bool
    {
        return $this->hasRole('System Administrator');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->hasRole('Platform Admin');
    }

    public function canImpersonate(): bool
    {
        return $this->isPlatformAdmin() && ! session()->has('impersonator_id');
    }

    public function canManagePlatform(): bool
    {
        if ($this->isPlatformAdmin() || $this->isSystemAdministrator()) {
            return true;
        }

        return $this->hasModulePermission('auth', 'read');
    }

    public function hasModulePermission(string $module, string $action): bool
    {
        // Platform Admin and System Administrator can perform every tenant action.
        if ($this->isPlatformAdmin() || $this->isSystemAdministrator()) {
            return true;
        }

        return $this->hasPermissionTo(ModulePermission::name($module, $action));
    }

    /** @return list<string> */
    public function modulePermissions(): array
    {
        if ($this->isPlatformAdmin() || $this->isSystemAdministrator()) {
            return ModulePermission::allPermissionNames();
        }

        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function canOverrideLimits(): bool
    {
        return $this->hasModulePermission('requisitions', 'override')
            || $this->hasModulePermission('budgets', 'override');
    }
}
