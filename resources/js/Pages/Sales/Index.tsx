import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, Sale } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

interface SalesIndexProps extends PageProps {
    sales: Paginated<Sale>;
    filters: ListingFilters & { status?: string };
}

export default function SalesIndex() {
    const { sales, filters } = usePage<SalesIndexProps>().props;
    const rows = sales.data ?? [];

    return (
        <AppShell title="Sales">
            <Head title="Sales" />
            <div className="space-y-6">
                <PageHeader
                    title="Sales"
                    description="Net sales by phase — closing a phase converts surplus (after carried deficits); mark an underwater project as Loss to record a company receivable."
                />

                <ListToolbar
                    baseUrl="/sales"
                    filters={filters}
                    searchPlaceholder="Search sale ID, customer, project, phase…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date created' },
                        { value: 'sale_code', label: 'Sale ID' },
                        { value: 'status', label: 'Status' },
                        { value: 'converted_at', label: 'Converted date' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: [
                                { value: 'open', label: 'Open' },
                                { value: 'receivable', label: 'Receivable' },
                                { value: 'partially_paid', label: 'Partially Paid' },
                                { value: 'paid', label: 'Paid' },
                            ],
                        },
                    ]}
                />

                <DataPanel title="Net Sales" noPadding>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    <th className="px-6 py-3 font-medium">Sale ID</th>
                                    <th className="px-6 py-3 font-medium">Customer</th>
                                    <th className="px-6 py-3 font-medium">Project</th>
                                    <th className="px-6 py-3 font-medium">Phase</th>
                                    <th className="px-6 py-3 text-right font-medium">Phase Disbursed</th>
                                    <th className="px-6 py-3 text-right font-medium">Profit Share</th>
                                    <th className="px-6 py-3 text-right font-medium">Outstanding</th>
                                    <th className="px-6 py-3 font-medium">Status</th>
                                    <th className="px-6 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="px-6 py-12 text-center text-slate-500">
                                            No sales yet. Add a project phase to generate a net sale.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((sale) => (
                                        <tr key={sale.id} className="hover:bg-slate-50">
                                            <td className="px-6 py-4 font-mono text-slate-900">
                                                {sale.sale_code}
                                            </td>
                                            <td className="px-6 py-4 text-slate-900">
                                                {sale.customer ?? sale.project?.client ?? '—'}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600">
                                                {sale.project
                                                    ? `${sale.project.code} — ${sale.project.name}`
                                                    : '—'}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600">
                                                {sale.phase
                                                    ? `Phase ${sale.phase.sequence_no}: ${sale.phase.name}`
                                                    : 'Legacy'}
                                            </td>
                                            <td className="px-6 py-4 text-right text-slate-900">
                                                {formatCurrency(sale.contract_amount)}
                                            </td>
                                            <td className="px-6 py-4 text-right font-medium text-green-700">
                                                {formatCurrency(sale.profit_amount)}
                                            </td>
                                            <td className="px-6 py-4 text-right text-slate-700">
                                                {formatCurrency(sale.outstanding_amount)}
                                            </td>
                                            <td className="px-6 py-4">
                                                <StatusBadge status={sale.status} />
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <Link href={`/sales/${sale.id}`}>
                                                    <Button variant="ghost" size="sm">
                                                        <Eye className="h-4 w-4" />
                                                        View
                                                    </Button>
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="border-t border-slate-100 px-6 py-3">
                        <PaginationLinks paginator={sales} />
                    </div>
                </DataPanel>
            </div>
        </AppShell>
    );
}
