<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MissingModulePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_permissions_deny_access_without_throwing(): void
    {
        $tenant = Tenant::create(['name' => 'Missing Permissions', 'slug' => 'missing-permissions']);

        $tenant->run(function () {
            $user = User::factory()->create();

            Permission::where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->assertFalse(Permission::where('name', 'auth:read')->where('guard_name', 'web')->exists());
            $this->assertFalse($user->hasModulePermission('auth', 'read'));
            $this->assertFalse($user->canManagePlatform());
            $this->assertFalse($user->canOverrideLimits());
        });
    }

    public function test_existing_permissions_still_require_an_assignment(): void
    {
        $tenant = Tenant::create(['name' => 'Assigned Permissions', 'slug' => 'assigned-permissions']);

        $tenant->run(function () {
            $user = User::factory()->create();
            $permission = Permission::findOrCreate('auth:read', 'web');

            $this->assertFalse($user->canManagePlatform());

            $user->givePermissionTo($permission);

            $this->assertTrue($user->hasModulePermission('auth', 'read'));
            $this->assertTrue($user->canManagePlatform());
        });
    }
}
