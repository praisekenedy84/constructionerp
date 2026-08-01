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
     * Layer 4: tenant-defined parent / child order (presentation only)
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
        $order = is_array($overrides['order'] ?? null) ? $overrides['order'] : [];
        $childOrder = is_array($overrides['child_order'] ?? null) ? $overrides['child_order'] : [];
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
                $childKeys = is_array($childOrder[$item['key']] ?? null)
                    ? $childOrder[$item['key']]
                    : [];
                $entry['children'] = $this->applyKeyOrder($children, $childKeys);
            }

            $visible[] = $entry;
        }

        return $this->applyKeyOrder($visible, $order);
    }

    /**
     * @param  list<array{key: string}>  $items
     * @param  list<string>  $orderedKeys
     * @return list<array{key: string}>
     */
    private function applyKeyOrder(array $items, array $orderedKeys): array
    {
        if ($orderedKeys === [] || $items === []) {
            return $items;
        }

        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item['key']] = $item;
        }

        $sorted = [];
        foreach ($orderedKeys as $key) {
            if (! isset($byKey[$key])) {
                continue;
            }
            $sorted[] = $byKey[$key];
            unset($byKey[$key]);
        }

        foreach ($byKey as $item) {
            $sorted[] = $item;
        }

        return $sorted;
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
