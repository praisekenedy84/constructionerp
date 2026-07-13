<?php

namespace App\Services;

use App\Models\User;
use App\Support\MenuCatalog;

class MenuService
{
    /**
     * Resolve visible navigation for a user.
     * Layer 1: permission (access policy)
     * Layer 2: tenant menu overrides per role (presentation only)
     * Layer 3: global hidden items (presentation only)
     *
     * @param  array<string, mixed>  $uiSettings
     * @return list<array{key: string, label: string, href: string, group: string}>
     */
    public function visibleForUser(User $user, array $uiSettings): array
    {
        $overrides = $uiSettings['nav_overrides'] ?? [];
        $globalHidden = $overrides['hidden'] ?? [];
        $roleHidden = $overrides['role_hidden'] ?? [];
        $userRoles = $user->getRoleNames()->toArray();

        $hiddenForUser = $globalHidden;
        foreach ($userRoles as $role) {
            if (isset($roleHidden[$role]) && is_array($roleHidden[$role])) {
                $hiddenForUser = array_merge($hiddenForUser, $roleHidden[$role]);
            }
        }
        $hiddenForUser = array_unique($hiddenForUser);

        $visible = [];

        foreach (MenuCatalog::items() as $item) {
            if (in_array($item['href'], $hiddenForUser, true)) {
                continue;
            }

            if ($item['permission'] && ! $user->hasModulePermission(...$this->parsePermission($item['permission']))) {
                continue;
            }

            if ($item['key'] === 'admin' && ! $user->canManagePlatform()) {
                continue;
            }

            $visible[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'href' => $item['href'],
                'group' => $item['group'],
            ];
        }

        return $visible;
    }

    /** @return array{0: string, 1: string} */
    private function parsePermission(string $permission): array
    {
        [$module, $action] = explode(':', $permission, 2);

        return [$module, $action];
    }
}
