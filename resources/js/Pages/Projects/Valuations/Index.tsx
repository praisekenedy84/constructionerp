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
    summary: {
        contract_amount: string;
        total_compliance: string;
        net_project_amount: string;
    };
}

export default function ValuationsIndex() {
    const { project, valuations, filters, summary } = usePage<ValuationsIndexProps>().props;
    const rows = valuations.data ?? [];

    return (
        <AppShell title="IPCs">
            <Head title={`IPCs — ${project.name}`} />
            <div className="space-y-6">
                <PageHeader
                    title="Interim Payment Certificates"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <Link href={`/projects/${project.id}/valuations/create`}>
                            <Button>
                                <Plus className="h-4 w-4" />
                                Add IPC
                            </Button>
                        </Link>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Contract amount
                        </p>
                        <p className="mt-1 text-lg font-semibold text-slate-900">
                            {formatCurrency(summary.contract_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Sum of IPCs&apos; compliance
                        </p>
                        <p className="mt-1 text-lg font-semibold text-red-600">
                            −{formatCurrency(summary.total_compliance)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Net project amount
                        </p>
                        <p className="mt-1 text-lg font-semibold text-slate-900">
                            {formatCurrency(summary.net_project_amount)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            Contract − Sum of IPCs&apos; compliance rules
                        </p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl={`/projects/${project.id}/valuations`}
                    filters={filters}
                    searchPlaceholder="Search status…"
                    sortOptions={[
                        { value: 'certificate_no', label: 'IPC no' },
                        { value: 'total_deductions', label: 'Compliance total' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="IPCs" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">IPC</th>
                                <th className="px-6 py-3 text-right font-medium">
                                    Total compliance rules
                                </th>
                                <th className="px-6 py-3 font-medium">Rules</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No IPCs yet. Add IPC-1 and enter its compliance rules.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((val) => (
                                    <tr key={val.id} className="hover:bg-slate-50/80">
                                        <td className="px-6 py-4">
                                            <Link
                                                href={`/projects/${project.id}/valuations/${val.id}`}
                                                className="font-mono font-medium text-slate-900 hover:underline"
                                            >
                                                IPC-{val.certificate_no}
                                            </Link>
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium text-red-600">
                                            −{formatCurrency(val.total_deductions)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {val.deductions?.length ?? 0} rule
                                            {(val.deductions?.length ?? 0) === 1 ? '' : 's'}
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
