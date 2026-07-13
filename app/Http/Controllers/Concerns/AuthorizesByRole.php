<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;

trait AuthorizesByRole
{
    protected function authorizeRoles(User $user, array|string $roles): void
    {
        if ($user->isSuperUser()) {
            return;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        if (! $user->hasAnyRole($roles)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    protected function authorizePermission(User $user, string $module, string $action): void
    {
        if (! $user->hasModulePermission($module, $action)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
