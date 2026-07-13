import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, Project, Valuation } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface ValuationsIndexProps extends PageProps {
    project: Project;
    valuations: Paginated<Valuation>;
    filters: ListingFilters;
}

export default function ValuationsIndex() {
    const { project, valuations, filters } = usePage<ValuationsIndexProps>().props;
    const rows = valuations.data ?? [];

    return (
        <AppShell title="Valuations">
            <Head title={`Valuations — ${project.name}`} />
            <div className="space-y-6">
                <PageHeader
                    title="IPC Valuations"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <Link href={`/projects/${project.id}/valuations/create`}>
                            <Button>
                                <Plus className="h-4 w-4" />
                                New Valuation
                            </Button>
                        </Link>
                    }
                />

                <ListToolbar
                    baseUrl={`/projects/${project.id}/valuations`}
                    filters={filters}
                    searchPlaceholder="Search status…"
                    sortOptions={[
                        { value: 'certificate_no', label: 'Certificate no' },
                        { value: 'gross_value', label: 'Gross value' },
                        { value: 'net_value', label: 'Net value' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Valuation Certificates" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Certificate No</th>
                                <th className="px-6 py-3 text-right font-medium">Gross</th>
                                <th className="px-6 py-3 text-right font-medium">Deductions</th>
                                <th className="px-6 py-3 text-right font-medium">Net</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No valuations yet.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((val) => (
                                    <tr key={val.id}>
                                        <td className="px-6 py-4 font-mono">
                                            IPC-{val.certificate_no}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {formatCurrency(val.gross_value)}
                                        </td>
                                        <td className="px-6 py-4 text-right text-red-600">
                                            {formatCurrency(val.total_deductions)}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(val.net_value)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(val.status)} />
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatDate(val.created_at)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={valuations} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
