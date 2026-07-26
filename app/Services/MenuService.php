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
     * @return list<array{
     *     key: string,
     *     label: string,
     *     href: string,
     *     group: string,
     *     active_path?: string,
     *     children?: list<array{key: string, label: string, href: string, group: string}>
     * }>
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
            if ($this->isHidden($item['href'], $hiddenForUser, $item['active_path'] ?? null)) {
                continue;
            }

            if ($item['permission'] && ! $user->hasModulePermission(...$this->parsePermission($item['permission']))) {
                continue;
            }

            if ($item['key'] === 'admin' && ! $user->canManagePlatform()) {
                continue;
            }

            $entry = [
                'key' => $item['key'],
                'label' => $item['label'],
                'href' => $item['href'],
                'group' => $item['group'],
            ];

            if (! empty($item['active_path'])) {
                $entry['active_path'] = $item['active_path'];
            }

            $children = [];
            foreach ($item['children'] ?? [] as $child) {
                if (in_array($child['href'], $hiddenForUser, true)) {
                    continue;
                }

                $permission = $child['permission'] ?? $item['permission'];
                if ($permission && ! $user->hasModulePermission(...$this->parsePermission($permission))) {
                    continue;
                }

                $children[] = [
                    'key' => $child['key'],
                    'label' => $child['label'],
                    'href' => $child['href'],
                    'group' => $item['group'],
                ];
            }

            if ($children !== []) {
                $entry['children'] = $children;
            }

            $visible[] = $entry;
        }

        return $visible;
    }

    /**
     * @param  list<string>  $hiddenForUser
     */
    private function isHidden(string $href, array $hiddenForUser, ?string $activePath): bool
    {
        if (in_array($href, $hiddenForUser, true)) {
            return true;
        }

        return $activePath !== null && in_array($activePath, $hiddenForUser, true);
    }

    /** @return array{0: string, 1: string} */
    private function parsePermission(string $permission): array
    {
        [$module, $action] = explode(':', $permission, 2);

        return [$module, $action];
    }
}
