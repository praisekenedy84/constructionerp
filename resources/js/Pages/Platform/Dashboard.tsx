import PlatformShell from '@/Components/Layout/PlatformShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Building2, Plus, ShieldAlert } from 'lucide-react';

interface PlatformDashboardProps extends PageProps {
    stats: {
        total_tenants: number;
        active_tenants: number;
        suspended_tenants: number;
    };
    recent_tenants: Array<{
        id: string;
        name: string;
        slug: string;
        status: string;
        created_at: string | null;
    }>;
}

export default function PlatformDashboard({ stats, recent_tenants }: PlatformDashboardProps) {
    return (
        <PlatformShell title="System Overview">
            <Head title="Platform Overview" />
            <div className="space-y-6">
                <PageHeader
                    title="System Overview"
                    description="Oversee all tenants, manage access, and maintain platform health."
                    className="[&_h2]:text-slate-900 [&_p]:text-slate-500 dark:[&_h2]:text-white dark:[&_p]:text-slate-400"
                    actions={
                        <Link href="/platform/tenants/create">
                            <Button className="bg-violet-600 hover:bg-violet-700">
                                <Plus className="mr-2 h-4 w-4" />
                                Add tenant
                            </Button>
                        </Link>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-slate-500 dark:text-slate-400">Total tenants</p>
                        <p className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">{stats.total_tenants}</p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-slate-500 dark:text-slate-400">Active</p>
                        <p className="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">{stats.active_tenants}</p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-slate-500 dark:text-slate-400">Suspended</p>
                        <p className="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400">{stats.suspended_tenants}</p>
                    </div>
                </div>

                <DataPanel title="Recent tenants">
                    {recent_tenants.length === 0 ? (
                        <p className="text-sm text-slate-500 dark:text-slate-400">No tenants yet. Create your first company.</p>
                    ) : (
                        <div className="divide-y divide-slate-200 dark:divide-slate-800">
                            {recent_tenants.map((tenant) => (
                                <div
                                    key={tenant.id}
                                    className="flex items-center justify-between py-4 first:pt-0 last:pb-0"
                                >
                                    <div className="flex items-center gap-3">
                                        <Building2 className="h-5 w-5 text-violet-600 dark:text-violet-400" />
                                        <div>
                                            <Link
                                                href={`/platform/tenants/${tenant.id}`}
                                                className="font-medium text-slate-900 hover:text-violet-700 dark:text-white dark:hover:text-violet-300"
                                            >
                                                {tenant.name}
                                            </Link>
                                            <p className="text-xs text-slate-500">{tenant.slug}</p>
                                        </div>
                                    </div>
                                    <StatusBadge status={tenant.status} />
                                </div>
                            ))}
                        </div>
                    )}
                </DataPanel>

                <div className="rounded-lg border border-violet-200 bg-violet-50 p-4 dark:border-violet-500/20 dark:bg-violet-500/5">
                    <div className="flex items-start gap-3">
                        <ShieldAlert className="mt-0.5 h-5 w-5 text-violet-600 dark:text-violet-400" />
                        <div>
                            <p className="text-sm font-medium text-violet-900 dark:text-violet-200">Platform capabilities</p>
                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                                Provision new companies, suspend tenants, lock user accounts, and impersonate
                                users for support and auditing.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </PlatformShell>
    );
}
