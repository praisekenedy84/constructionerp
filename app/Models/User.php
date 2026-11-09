<?php

namespace App\Models;

use App\Services\CompanyAccessService;
use App\Support\ModulePermission;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'username',
        'phone',
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
        if (CompanyAccessService::visiting()) {
            return false;
        }

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
        return ! CompanyAccessService::visiting() && $this->isPlatformAdmin() && ! session()->has('impersonator_id');
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
        if (CompanyAccessService::visiting()) {
            return $action === 'read' && in_array($module.':read', request()->attributes->get('home_permissions', []), true);
        }
        // Platform Admin and System Administrator can perform every tenant action.
        if ($this->isPlatformAdmin() || $this->isSystemAdministrator()) {
            return true;
        }

        // An incomplete tenant permission catalog must deny access, not crash requests.
        return $this->checkPermissionTo(ModulePermission::name($module, $action));
    }

    /** @return list<string> */
    public function modulePermissions(): array
    {
        if (CompanyAccessService::visiting() && request()->attributes->has('home_permissions')) {
            return array_values(array_filter(request()->attributes->get('home_permissions'), fn ($permission) => str_ends_with($permission, ':read')));
        }
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
