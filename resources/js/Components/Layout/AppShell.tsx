import { NavItem, PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    ChevronDown,
    ClipboardList,
    LayoutDashboard,
    LogOut,
    Menu,
    Package,
    Receipt,
    Settings,
    ShoppingCart,
    Users,
    Wallet,
    XCircle,
} from 'lucide-react';
import { ReactNode, useEffect, useId, useState } from 'react';
import { isNavChildActive, isNavItemActive, isNavSectionActive } from '@/lib/permissions';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/Components/ui/sheet';
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

function persistExpandedKeys(keys: string[]) {
    localStorage.setItem(EXPANDED_NAV_STORAGE_KEY, JSON.stringify(keys));
}

export default function AppShell({ title, children }: AppShellProps) {
    const page = usePage<PageProps>();
    const { auth, uiSettings, navigation, unreadNotificationCount, flash } = page.props;
    const { url } = page;
    const visibleNav = navigation ?? [];
    const isImpersonating = Boolean(auth.impersonator_id || auth.platform_impersonator_id);
    const isPlatformImpersonation = Boolean(auth.platform_impersonator_id);
    const [mobileOpen, setMobileOpen] = useState(false);
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
            persistExpandedKeys(next);
            return next;
        });
    }, [url, visibleNav]);

    useEffect(() => {
        setMobileOpen(false);
    }, [url]);

    function setItemExpanded(key: string, open: boolean) {
        setExpandedKeys((prev) => {
            const isOpen = prev.includes(key);
            if (open === isOpen) {
                return prev;
            }
            const next = open ? [...prev, key] : prev.filter((item) => item !== key);
            persistExpandedKeys(next);
            return next;
        });
    }

    const brand = (
        <div className="flex items-center gap-2">
            <Building2 className="h-6 w-6 shrink-0 text-blue-700 dark:text-blue-400" />
            <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                    {uiSettings.app_name}
                </p>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">{uiSettings.tagline}</p>
            </div>
        </div>
    );

    const sidebarNavProps = {
        items: visibleNav,
        url,
        expandedKeys,
        onOpenChange: setItemExpanded,
    };

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
                    'fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-200 bg-white md:flex dark:border-slate-800 dark:bg-slate-900',
                    isImpersonating && 'top-10',
                )}
            >
                <div className="flex h-16 shrink-0 items-center border-b border-slate-200 px-6 dark:border-slate-800">
                    {brand}
                </div>
                <nav className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4" aria-label="Main">
                    <SidebarNav {...sidebarNavProps} />
                </nav>
            </aside>

            <div className={cn('md:pl-64', isImpersonating && 'pt-10')}>
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 sm:px-8 dark:border-slate-800 dark:bg-slate-900/80 dark:backdrop-blur">
                    <div className="flex min-w-0 items-center gap-3">
                        <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                            <SheetTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="shrink-0 px-2 md:hidden"
                                    aria-label="Open navigation menu"
                                >
                                    <Menu className="h-5 w-5" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="left" className="flex w-72 flex-col p-0 sm:max-w-sm">
                                <SheetHeader className="border-b border-slate-200 dark:border-slate-800">
                                    <SheetTitle>{uiSettings.app_name}</SheetTitle>
                                    <SheetDescription>{uiSettings.tagline}</SheetDescription>
                                </SheetHeader>
                                <nav
                                    className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4"
                                    aria-label="Mobile main"
                                >
                                    <SidebarNav
                                        {...sidebarNavProps}
                                        onNavigate={() => setMobileOpen(false)}
                                    />
                                </nav>
                            </SheetContent>
                        </Sheet>
                        <h1 className="truncate text-lg font-semibold text-slate-900 dark:text-white">
                            {title ?? 'Dashboard'}
                        </h1>
                    </div>
                    <div className="flex shrink-0 items-center gap-2 sm:gap-4">
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
                        <div className="hidden text-right sm:block">
                            <p className="text-sm font-medium text-slate-900 dark:text-slate-200">
                                {auth.user?.name}
                            </p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                {auth.user?.roles[0]}
                            </p>
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
                <main className="p-4 sm:p-8">
                    {(flash?.success || flash?.error) && (
                        <div className="mb-6 space-y-2">
                            {flash.success && (
                                <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-200">
                                    {flash.success}
                                </div>
                            )}
                            {flash.error && (
                                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                                    {flash.error}
                                </div>
                            )}
                        </div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}

function SidebarNav({
    items,
    url,
    expandedKeys,
    onOpenChange,
    onNavigate,
}: {
    items: NavItem[];
    url: string;
    expandedKeys: string[];
    onOpenChange: (key: string, open: boolean) => void;
    onNavigate?: () => void;
}) {
    return (
        <ul className="space-y-1" role="list">
            {items.map((item) => (
                <li key={item.key}>
                    <NavGroup
                        item={item}
                        url={url}
                        expanded={expandedKeys.includes(item.key)}
                        onOpenChange={(open) => onOpenChange(item.key, open)}
                        onNavigate={onNavigate}
                    />
                </li>
            ))}
        </ul>
    );
}

function NavGroup({
    item,
    url,
    expanded,
    onOpenChange,
    onNavigate,
}: {
    item: NavItem;
    url: string;
    expanded: boolean;
    onOpenChange: (open: boolean) => void;
    onNavigate?: () => void;
}) {
    const submenuId = useId();
    const children = item.children ?? [];
    const hasChildren = children.length > 0;
    const iconHref = item.active_path ?? item.href;
    const sectionActive = isNavSectionActive(item.href, url, children, item.active_path);
    const parentActive = hasChildren ? false : isNavItemActive(item.href, url);

    if (!hasChildren) {
        return (
            <Link
                href={item.href}
                onClick={onNavigate}
                className={cn(
                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                    parentActive
                        ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                )}
                aria-current={parentActive ? 'page' : undefined}
            >
                <NavIcon href={iconHref} active={parentActive} />
                {item.label}
            </Link>
        );
    }

    return (
        <Collapsible open={expanded} onOpenChange={onOpenChange}>
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600',
                        sectionActive
                            ? 'bg-slate-100/80 font-medium text-blue-700 dark:bg-slate-800/80 dark:text-blue-300'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                    )}
                    aria-controls={submenuId}
                >
                    <NavIcon href={iconHref} active={sectionActive} />
                    <span className="min-w-0 flex-1 truncate">{item.label}</span>
                    <ChevronDown
                        className={cn(
                            'h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200',
                            expanded && 'rotate-180',
                        )}
                        aria-hidden
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul
                    id={submenuId}
                    role="list"
                    className="mt-1 ml-4 space-y-0.5 border-l border-slate-200 pl-2 dark:border-slate-700"
                >
                    {children.map((child) => {
                        const childActive = isNavChildActive(child.href, url, children);

                        return (
                            <li key={child.key}>
                                <Link
                                    href={child.href}
                                    onClick={onNavigate}
                                    className={cn(
                                        'block rounded-md px-3 py-1.5 text-sm transition-colors',
                                        childActive
                                            ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                                    )}
                                    aria-current={childActive ? 'page' : undefined}
                                >
                                    {child.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}

function NavIcon({ href, active }: { href: string; active: boolean }) {
    const icons: Record<string, typeof LayoutDashboard> = {
        '/dashboard': LayoutDashboard,
        '/projects': Building2,
        '/sales': ShoppingCart,
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

    return <Icon className={cn('h-4 w-4 shrink-0', color)} aria-hidden />;
}
