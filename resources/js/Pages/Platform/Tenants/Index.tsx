import PlatformShell from '@/Components/Layout/PlatformShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { ListingFilters, PageProps, Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface TenantRow {
    id: string;
    name: string;
    slug: string;
    status: string;
    suspended_at: string | null;
    suspended_reason: string | null;
    created_at: string | null;
    total_users: number;
    locked_users: number;
}

interface PlatformTenantsIndexProps extends PageProps {
    tenants: Paginated<TenantRow>;
    filters: ListingFilters;
    statuses: { value: string; label: string }[];
}

export default function PlatformTenantsIndex() {
    const { tenants, filters } = usePage<PlatformTenantsIndexProps>().props;
    const rows = tenants.data ?? [];

    return (
        <PlatformShell title="Tenants">
            <Head title="Tenants" />
            <div className="space-y-6">
                <PageHeader
                    title="Tenants & Companies"
                    description="Manage all construction companies on the platform."
                    actions={
                        <Link href="/platform/tenants/create">
                            <Button className="bg-violet-600 hover:bg-violet-700">
                                <Plus className="mr-2 h-4 w-4" />
                                Add tenant
                            </Button>
                        </Link>
                    }
                />

                <ListToolbar
                    baseUrl="/platform/tenants"
                    filters={filters}
                    searchPlaceholder="Search company name, slug…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'slug', label: 'Slug' },
                        { value: 'status', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="All tenants" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-800">
                                <th className="px-6 py-3 font-medium">Company</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Users</th>
                                <th className="px-6 py-3 font-medium">Locked</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No tenants found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((tenant) => (
                                    <tr key={tenant.id} className="text-slate-700 dark:text-slate-300">
                                        <td className="px-6 py-4">
                                            <p className="font-medium text-slate-900 dark:text-white">{tenant.name}</p>
                                            <p className="text-xs text-slate-500">{tenant.slug}</p>
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={tenant.status} />
                                        </td>
                                        <td className="px-6 py-4">{tenant.total_users}</td>
                                        <td className="px-6 py-4">{tenant.locked_users}</td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/platform/tenants/${tenant.id}`}>
                                                <Button size="sm" variant="outline">
                                                    Manage
                                                </Button>
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={tenants} />
                </DataPanel>
            </div>
        </PlatformShell>
    );
}
