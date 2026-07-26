import { NavItem, PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    ChevronDown,
    ClipboardList,
    LayoutDashboard,
    LogOut,
    Package,
    Receipt,
    Settings,
    Users,
    Wallet,
    XCircle,
} from 'lucide-react';
import { ReactNode, useEffect, useState } from 'react';
import { isNavChildActive, isNavItemActive, isNavSectionActive } from '@/lib/permissions';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import ThemeToggle from '@/Components/Shared/ThemeToggle';

interface AppShellProps {
    title?: string;
    children: ReactNode;
}

const EXPANDED_NAV_STORAGE_KEY = 'crf-sidebar-expanded';

function readExpandedKeys(): string[] {
    try {
        const raw = localStorage.getItem(EXPANDED_NAV_STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.filter((key): key is string => typeof key === 'string') : [];
    } catch {
        return [];
    }
}

export default function AppShell({ title, children }: AppShellProps) {
    const page = usePage<PageProps>();
    const { auth, uiSettings, navigation, unreadNotificationCount } = page.props;
    const { url } = page;
    const visibleNav = navigation ?? [];
    const isImpersonating = Boolean(auth.impersonator_id || auth.platform_impersonator_id);
    const isPlatformImpersonation = Boolean(auth.platform_impersonator_id);
    const [expandedKeys, setExpandedKeys] = useState<string[]>(() =>
        typeof window === 'undefined' ? [] : readExpandedKeys(),
    );

    useEffect(() => {
        const activeParents = visibleNav
            .filter(
                (item) =>
                    item.children?.length &&
                    isNavSectionActive(item.href, url, item.children, item.active_path),
            )
            .map((item) => item.key);

        if (activeParents.length === 0) {
            return;
        }

        setExpandedKeys((prev) => {
            const next = Array.from(new Set([...prev, ...activeParents]));
            if (next.length === prev.length && next.every((key) => prev.includes(key))) {
                return prev;
            }
            localStorage.setItem(EXPANDED_NAV_STORAGE_KEY, JSON.stringify(next));
            return next;
        });
    }, [url, visibleNav]);

    function toggleExpanded(key: string) {
        setExpandedKeys((prev) => {
            const next = prev.includes(key) ? prev.filter((item) => item !== key) : [...prev, key];
            localStorage.setItem(EXPANDED_NAV_STORAGE_KEY, JSON.stringify(next));
            return next;
        });
    }

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
            {isImpersonating && (
                <div className="fixed inset-x-0 top-0 z-50 flex items-center justify-between bg-amber-500 px-6 py-2 text-sm text-white">
                    <span>
                        {isPlatformImpersonation ? 'Platform impersonation: ' : 'Impersonating '}
                        <strong>{auth.user?.name}</strong>
                    </span>
                    <Link href="/auth/exit-impersonation" method="post" as="button">
                        <Button
                            size="sm"
                            variant="outline"
                            className="border-white/40 bg-transparent text-white hover:bg-white/10"
                        >
                            <XCircle className="mr-1 h-4 w-4" />
                            Exit impersonation
                        </Button>
                    </Link>
                </div>
            )}

            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-30 flex w-64 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
                    isImpersonating && 'top-10',
                )}
            >
                <div className="flex h-16 shrink-0 items-center gap-2 border-b border-slate-200 px-6 dark:border-slate-800">
                    <Building2 className="h-6 w-6 text-blue-700 dark:text-blue-400" />
                    <div>
                        <p className="text-sm font-semibold text-slate-900 dark:text-white">{uiSettings.app_name}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{uiSettings.tagline}</p>
                    </div>
                </div>
                <nav
                    className="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain p-4"
                    aria-label="Main"
                >
                    {visibleNav.map((item) => (
                        <NavGroup
                            key={item.key}
                            item={item}
                            url={url}
                            expanded={expandedKeys.includes(item.key)}
                            onToggle={() => toggleExpanded(item.key)}
                        />
                    ))}
                </nav>
            </aside>

            <div className={cn('pl-64', isImpersonating && 'pt-10')}>
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-8 dark:border-slate-800 dark:bg-slate-900/80 dark:backdrop-blur">
                    <h1 className="text-lg font-semibold text-slate-900 dark:text-white">{title ?? 'Dashboard'}</h1>
                    <div className="flex items-center gap-4">
                        <ThemeToggle className="text-slate-500 dark:text-slate-400" />
                        <Link
                            href="/notifications"
                            className="relative rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                            aria-label="Notifications"
                        >
                            <Bell className="h-5 w-5" />
                            {unreadNotificationCount > 0 && (
                                <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] text-white">
                                    {unreadNotificationCount}
                                </span>
                            )}
                        </Link>
                        <div className="text-right">
                            <p className="text-sm font-medium text-slate-900 dark:text-slate-200">{auth.user?.name}</p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">{auth.user?.roles[0]}</p>
                        </div>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        >
                            <LogOut className="h-5 w-5" />
                        </Link>
                    </div>
                </header>
                <main className="p-8">{children}</main>
            </div>
        </div>
    );
}

function NavGroup({
    item,
    url,
    expanded,
    onToggle,
}: {
    item: NavItem;
    url: string;
    expanded: boolean;
    onToggle: () => void;
}) {
    const children = item.children ?? [];
    const hasChildren = children.length > 0;
    const iconHref = item.active_path ?? item.href;
    const sectionActive = isNavSectionActive(item.href, url, children, item.active_path);
    const parentActive = hasChildren ? false : isNavItemActive(item.href, url);

    if (!hasChildren) {
        return (
            <Link
                href={item.href}
                className={cn(
                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                    parentActive
                        ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                )}
            >
                <NavIcon href={iconHref} active={parentActive} />
                {item.label}
            </Link>
        );
    }

    return (
        <div>
            <div
                className={cn(
                    'flex items-center rounded-md transition-colors',
                    sectionActive
                        ? 'text-blue-700 dark:text-blue-300'
                        : 'text-slate-600 dark:text-slate-400',
                )}
            >
                <Link
                    href={item.href}
                    className={cn(
                        'flex min-w-0 flex-1 items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                        'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                        sectionActive && 'font-medium',
                    )}
                >
                    <NavIcon href={iconHref} active={sectionActive} />
                    <span className="truncate">{item.label}</span>
                </Link>
                <button
                    type="button"
                    onClick={onToggle}
                    className="mr-1 rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                    aria-expanded={expanded}
                    aria-label={`${expanded ? 'Collapse' : 'Expand'} ${item.label} submenu`}
                >
                    <ChevronDown
                        className={cn('h-4 w-4 transition-transform', expanded && 'rotate-180')}
                    />
                </button>
            </div>
            {expanded && (
                <div className="ml-4 space-y-0.5 border-l border-slate-200 pl-2 dark:border-slate-700">
                    {children.map((child) => {
                        const childActive = isNavChildActive(child.href, url, children);

                        return (
                            <Link
                                key={child.key}
                                href={child.href}
                                className={cn(
                                    'block rounded-md px-3 py-1.5 text-sm transition-colors',
                                    childActive
                                        ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                                )}
                            >
                                {child.label}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function NavIcon({ href, active }: { href: string; active: boolean }) {
    const icons: Record<string, typeof LayoutDashboard> = {
        '/dashboard': LayoutDashboard,
        '/projects': Building2,
        '/requisitions': ClipboardList,
        '/finance': Wallet,
        '/procurement': Receipt,
        '/inventory': Package,
        '/payroll': Users,
        '/equipment': Settings,
        '/reports': ClipboardList,
        '/audit': ClipboardList,
        '/admin/users': Users,
    };

    const Icon = icons[href] ?? LayoutDashboard;
    const color = active ? 'text-blue-700 dark:text-blue-300' : 'text-slate-400';

    return <Icon className={cn('h-4 w-4', color)} />;
}
