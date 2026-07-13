import { User } from '@/types';

const SUPER_ROLES = ['Platform Admin', 'System Administrator', 'Managing Director'];

export function isSuperUser(user: User | null): boolean {
    if (!user) return false;

    return (user.roles ?? []).some((role) => SUPER_ROLES.includes(role));
}

export function hasRole(user: User | null, roles: string | string[]): boolean {
    if (!user) return false;
    if (isSuperUser(user)) return true;

    const required = Array.isArray(roles) ? roles : [roles];

    return required.some((role) => (user.roles ?? []).includes(role));
}

export function hasPermission(user: User | null, module: string, action: string): boolean {
    if (!user) return false;
    if (isSuperUser(user)) return true;

    const key = `${module}:${action}`;

    return (user.permissions ?? []).includes(key);
}

export interface NavItem {
    label: string;
    href: string;
    permission?: { module: string; action: string };
    roles?: string[];
}

export const navItems: NavItem[] = [
    { label: 'Dashboard', href: '/dashboard' },
    { label: 'Projects', href: '/projects', permission: { module: 'projects', action: 'read' } },
    { label: 'Requisitions', href: '/requisitions', permission: { module: 'requisitions', action: 'read' } },
    { label: 'Finance', href: '/finance', permission: { module: 'budgets', action: 'read' } },
    { label: 'Procurement', href: '/procurement', permission: { module: 'procurement', action: 'read' } },
    { label: 'Inventory', href: '/inventory', permission: { module: 'inventory', action: 'read' } },
    { label: 'Payroll', href: '/payroll', permission: { module: 'payroll', action: 'read' } },
    { label: 'Equipment', href: '/equipment', permission: { module: 'equipment', action: 'read' } },
    { label: 'Reports', href: '/reports', permission: { module: 'reports', action: 'read' } },
    { label: 'Audit', href: '/audit', permission: { module: 'audit', action: 'read' } },
    { label: 'Admin', href: '/admin/users', roles: ['Platform Admin', 'System Administrator'] },
];

export function filterNav(
    items: NavItem[],
    user: User | null,
    hiddenHrefs: string[] = [],
): NavItem[] {
    return items.filter((item) => {
        if (hiddenHrefs.includes(item.href)) return false;
        if (item.permission) {
            return hasPermission(user, item.permission.module, item.permission.action);
        }
        if (item.roles) {
            return hasRole(user, item.roles);
        }

        return true;
    });
}

export function isNavItemActive(href: string, url: string): boolean {
    const path = url.split('?')[0].split('#')[0];

    if (href === '/dashboard') {
        return path === '/dashboard';
    }

    if (href === '/admin/users') {
        return path.startsWith('/admin') || path === '/settings/ui';
    }

    return path === href || path.startsWith(`${href}/`);
}

export function canOverrideLimits(user: User | null): boolean {
    return hasRole(user, ['Finance Manager', 'Managing Director', 'System Administrator']);
}
