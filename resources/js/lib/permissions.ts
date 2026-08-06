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

    // Full-access tenant/platform admins bypass the checkbox matrix.
    const roles = user.roles ?? [];
    if (roles.includes('Platform Admin') || roles.includes('System Administrator')) {
        return true;
    }

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
    { label: 'Sales', href: '/sales', permission: { module: 'sales', action: 'read' } },
    { label: 'Requisitions', href: '/requisitions', permission: { module: 'requisitions', action: 'read' } },
    { label: 'Finance', href: '/finance/approvals', permission: { module: 'budgets', action: 'read' } },
    { label: 'Procurement', href: '/procurement/suppliers', permission: { module: 'procurement', action: 'read' } },
    { label: 'Inventory', href: '/inventory/balances', permission: { module: 'inventory', action: 'read' } },
    { label: 'Payroll', href: '/payroll/employees', permission: { module: 'payroll', action: 'read' } },
    { label: 'Equipment', href: '/equipment', permission: { module: 'equipment', action: 'read' } },
    { label: 'Reports', href: '/reports', permission: { module: 'reports', action: 'read' } },
    { label: 'Audit', href: '/audit', permission: { module: 'audit', action: 'read' } },
    { label: 'Admin', href: '/admin/users', permission: { module: 'auth', action: 'read' } },
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

function currentPath(url: string): string {
    return url.split('?')[0].split('#')[0];
}

export function isNavItemActive(href: string, url: string, exact = false): boolean {
    const path = currentPath(url);

    if (exact || href === '/dashboard') {
        return path === href;
    }

    if (href === '/admin/users') {
        return path.startsWith('/admin') || path === '/settings/ui';
    }

    return path === href || path.startsWith(`${href}/`);
}

/** Prefer the longest matching sibling so `/equipment` does not stay active on `/equipment/assignments`. */
export function isNavChildActive(
    href: string,
    url: string,
    siblings: Array<{ href: string }> = [],
): boolean {
    const path = currentPath(url);
    const matches = siblings.filter(
        (sibling) => path === sibling.href || path.startsWith(`${sibling.href}/`),
    );

    if (matches.length === 0) {
        return false;
    }

    const best = matches.reduce((a, b) => (a.href.length >= b.href.length ? a : b));

    return best.href === href;
}

/** True when the current path is within a parent section (active_path, or any child). */
export function isNavSectionActive(
    href: string,
    url: string,
    children: Array<{ href: string }> = [],
    activePath?: string,
): boolean {
    if (children.length === 0) {
        return isNavItemActive(href, url);
    }

    const path = currentPath(url);
    const section = activePath ?? href;

    if (path === section || path.startsWith(`${section}/`)) {
        return true;
    }

    return children.some((child) => isNavItemActive(child.href, url));
}

export function canOverrideLimits(user: User | null): boolean {
    return (
        hasPermission(user, 'requisitions', 'override') ||
        hasPermission(user, 'budgets', 'override')
    );
}
