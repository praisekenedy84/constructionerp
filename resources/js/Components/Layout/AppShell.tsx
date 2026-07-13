import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
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
import { ReactNode } from 'react';
import { isNavItemActive } from '@/lib/permissions';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import ThemeToggle from '@/Components/Shared/ThemeToggle';

interface AppShellProps {
    title?: string;
    children: ReactNode;
}

export default function AppShell({ title, children }: AppShellProps) {
    const page = usePage<PageProps>();
    const { auth, uiSettings, navigation, unreadNotificationCount } = page.props;
    const { url } = page;
    const visibleNav = navigation ?? [];
    const isImpersonating = Boolean(auth.impersonator_id || auth.platform_impersonator_id);
    const isPlatformImpersonation = Boolean(auth.platform_impersonator_id);

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
                    'fixed inset-y-0 left-0 z-30 w-64 border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
                    isImpersonating && 'top-10',
                )}
            >
                <div className="flex h-16 items-center gap-2 border-b border-slate-200 px-6 dark:border-slate-800">
                    <Building2 className="h-6 w-6 text-blue-700 dark:text-blue-400" />
                    <div>
                        <p className="text-sm font-semibold text-slate-900 dark:text-white">{uiSettings.app_name}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{uiSettings.tagline}</p>
                    </div>
                </div>
                <nav className="space-y-1 p-4">
                    {visibleNav.map((item) => {
                        const active = isNavItemActive(item.href, url);

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={cn(
                                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                                    active
                                        ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                                )}
                            >
                                <NavIcon href={item.href} active={active} />
                                {item.label}
                            </Link>
                        );
                    })}
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
