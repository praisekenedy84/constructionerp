import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Building2, LayoutDashboard, LogOut, Shield, Users } from 'lucide-react';
import { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import ThemeToggle from '@/Components/Shared/ThemeToggle';

interface PlatformShellProps {
    title?: string;
    children: ReactNode;
}

const navItems = [
    { label: 'Overview', href: '/platform', icon: LayoutDashboard },
    { label: 'Tenants', href: '/platform/tenants', icon: Building2 },
];

export default function PlatformShell({ title, children }: PlatformShellProps) {
    const page = usePage<PageProps>();
    const { auth } = page.props;
    const { url } = page;
    const admin = auth.platform_admin;

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
            <aside className="fixed inset-y-0 left-0 z-30 w-64 border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="flex h-16 items-center gap-2 border-b border-slate-200 px-6 dark:border-slate-800">
                    <Shield className="h-6 w-6 text-violet-600 dark:text-violet-400" />
                    <div>
                        <p className="text-sm font-semibold text-slate-900 dark:text-white">Platform Admin</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">System oversight</p>
                    </div>
                </div>
                <nav className="space-y-1 p-4">
                    {navItems.map((item) => {
                        const active =
                            item.href === '/platform'
                                ? url === '/platform' || url === '/platform/'
                                : url.startsWith(item.href);
                        const Icon = item.icon;

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={cn(
                                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                                    active
                                        ? 'bg-violet-500/10 font-medium text-violet-700 dark:text-violet-300'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
                                )}
                            >
                                <Icon className="h-4 w-4" />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </aside>

            <div className="pl-64">
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-8 dark:border-slate-800 dark:bg-slate-900/80 dark:backdrop-blur">
                    <h1 className="text-lg font-semibold text-slate-900 dark:text-white">{title ?? 'Platform'}</h1>
                    <div className="flex items-center gap-4">
                        <ThemeToggle className="text-slate-500 dark:text-slate-400" />
                        <div className="text-right">
                            <p className="text-sm font-medium text-slate-900 dark:text-slate-200">{admin?.name}</p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">{admin?.email}</p>
                        </div>
                        <Link href="/platform/logout" method="post" as="button">
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-slate-200 bg-transparent text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                <LogOut className="mr-1 h-4 w-4" />
                                Sign out
                            </Button>
                        </Link>
                    </div>
                </header>

                <main className="p-8">{children}</main>
            </div>
        </div>
    );
}
