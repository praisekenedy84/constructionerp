<?php

namespace App\Services;

use App\Models\CentralUser;
use App\Models\User;
use App\Support\MenuCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(private AuditService $auditService) {}

    /**
     * @param  array{name?: string, email?: string, password?: string|null, role?: string}  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $this->assertManageable($actor, $target);

        $before = [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => $target->getRoleNames()->values()->all(),
        ];

        return DB::transaction(function () use ($actor, $target, $data, $before) {
            $updates = [];

            if (isset($data['name'])) {
                $updates['name'] = $data['name'];
            }

            $identity = CentralUser::where('tenant_id', tenant('id'))->where('tenant_user_id', $target->id)->firstOrFail();
            if (isset($data['username'])) {
                $username = strtolower(trim($data['username']));
                if (CentralUser::where('tenant_id', tenant('id'))->where('username', $username)->where('id', '!=', $identity->id)->exists()) {
                    throw ValidationException::withMessages(['username' => 'This username is already in use in this company.']);
                }
                $updates['username'] = $username;
            }
            foreach (['email', 'phone'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }
            if (! empty($data['password'])) {
                $updates['password'] = Hash::make($data['password']);
            }

            if ($updates !== []) {
                $target->update($updates);
                $identity->update(['username' => $target->username, 'email' => $target->email]);
            }

            if (isset($data['role'])) {
                $this->assignRole($actor, $target, $data['role']);
            }

            $target->refresh();

            $this->auditService->write(
                'User',
                $target->id,
                'updated',
                $before,
                [
                    'name' => $target->name,
                    'email' => $target->email,
                    'roles' => $target->getRoleNames()->values()->all(),
                ],
                $actor->id,
            );

            return $target;
        });
    }

    public function delete(User $actor, User $target): void
    {
        $this->assertManageable($actor, $target);

        if ($actor->id === $target->id) {
            throw new AuthorizationException('You cannot delete your own account.');
        }

        if ($target->hasRole('System Administrator') && $this->countRoleHolders('System Administrator') <= 1) {
            throw new AuthorizationException('Cannot delete the last System Administrator.');
        }

        $tenantId = tenant('id');
        DB::transaction(function () use ($actor, $target, $tenantId) {
            $before = [
                'name' => $target->name,
                'email' => $target->email,
                'roles' => $target->getRoleNames()->values()->all(),
            ];

            tenancy()->central(function () use ($target, $tenantId) {
                CentralUser::where('tenant_id', $tenantId)->where('tenant_user_id', $target->id)->delete();
            });

            $target->delete();

            $this->auditService->write(
                'User',
                $target->id,
                'deleted',
                $before,
                null,
                $actor->id,
            );
        });
    }

    public function assignRole(User $actor, User $target, string $role): void
    {
        $this->assertAssignableRole($role);

        if ($actor->id === $target->id && $role !== 'System Administrator' && $actor->hasRole('System Administrator')) {
            if ($this->countRoleHolders('System Administrator') <= 1) {
                throw new AuthorizationException('Cannot remove your own System Administrator role while you are the last admin.');
            }
        }

        if (
            $target->hasRole('System Administrator')
            && $role !== 'System Administrator'
            && $this->countRoleHolders('System Administrator') <= 1
        ) {
            throw new AuthorizationException('Cannot remove the last System Administrator.');
        }

        $target->syncRoles([$role]);
    }

    private function assertManageable(User $actor, User $target): void
    {
        if (! $actor->canManagePlatform()) {
            throw new AuthorizationException('Tenant administration access required.');
        }

        if ($target->hasRole('Platform Admin')) {
            throw new AuthorizationException('Platform Admin accounts cannot be managed from tenant administration.');
        }
    }

    private function assertAssignableRole(string $role): void
    {
        if (! in_array($role, MenuCatalog::assignableRoles(), true)) {
            throw ValidationException::withMessages([
                'role' => ['This role cannot be assigned.'],
            ]);
        }
    }

    private function countRoleHolders(string $role): int
    {
        return User::role($role)->count();
    }
}
