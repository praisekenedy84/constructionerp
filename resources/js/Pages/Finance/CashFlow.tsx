import AppShell from '@/Components/Layout/AppShell';
import SimpleLineChart from '@/Components/Charts/SimpleLineChart';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { aggregateCashFlowTimeline } from '@/lib/chart-helpers';
import { CashAllocation, ListingFilters, PageProps, Paginated, Project } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface CashFlowProps extends PageProps {
    project: Project;
    allocations: Paginated<CashAllocation>;
    filters: ListingFilters;
}

export default function CashFlow() {
    const { project, allocations, filters } = usePage<CashFlowProps>().props;
    const rows = allocations.data ?? [];
    const cashFlowTimeline = aggregateCashFlowTimeline(rows);
    const { data, setData, post, processing, errors, reset } = useForm({
        project_id: project.id,
        requested_amount: '',
        method: '',
        reference_no: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/finance/cash-requests', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Cash Flow">
            <Head title="Cash Flow" />
            <div className="space-y-6">
                <PageHeader
                    title="Cash Flow"
                    description={`Request and track cash allocations for ${project.name}`}
                />

                <DataPanel title="Request Cash Allocation">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                        <div className="space-y-2">
                            <Label>Amount (TZS)</Label>
                            <Input
                                type="number"
                                step="0.01"
                                value={data.requested_amount}
                                onChange={(e) => setData('requested_amount', e.target.value)}
                                className="w-40"
                                required
                            />
                            {errors.requested_amount && (
                                <p className="text-sm text-red-600">{errors.requested_amount}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Method</Label>
                            <Input
                                value={data.method}
                                onChange={(e) => setData('method', e.target.value)}
                                placeholder="Bank transfer"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Reference No</Label>
                            <Input
                                value={data.reference_no}
                                onChange={(e) => setData('reference_no', e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={processing}>
                            Request
                        </Button>
                    </form>
                </DataPanel>

                <DataPanel
                    title="Cumulative Cash Flow"
                    description="Running totals of received and utilized cash over time"
                >
                    <SimpleLineChart
                        data={cashFlowTimeline}
                        xKey="date"
                        series={[
                            { key: 'received', name: 'Received', color: '#059669' },
                            { key: 'utilized', name: 'Utilized', color: '#1d4ed8' },
                        ]}
                    />
                </DataPanel>

                <ListToolbar
                    baseUrl={`/finance/${project.id}/cash-flow`}
                    filters={filters}
                    searchPlaceholder="Search reference, method…"
                    sortOptions={[
                        { value: 'requested_at', label: 'Requested date' },
                        { value: 'status', label: 'Status' },
                        { value: 'requested_amount', label: 'Requested amount' },
                        { value: 'received_amount', label: 'Received amount' },
                    ]}
                />

                <DataPanel title="Cash Allocations" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Requested</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 text-right font-medium">Received</th>
                                <th className="px-6 py-3 text-right font-medium">Utilized</th>
                                <th className="px-6 py-3 text-right font-medium">Balance</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-6 py-12 text-center text-slate-500">
                                        No cash allocations found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((alloc) => (
                                    <tr key={alloc.id} className="hover:bg-slate-50">
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatCurrency(alloc.requested_amount)}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(alloc.requested_amount)}
                                        </td>
                                        <td className="px-6 py-4 text-right text-green-700">
                                            {formatCurrency(alloc.received_amount)}
                                        </td>
                                        <td className="px-6 py-4 text-right text-slate-600">
                                            {formatCurrency(alloc.utilized_amount)}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(
                                                alloc.balance ??
                                                    String(
                                                        parseFloat(alloc.received_amount) -
                                                            parseFloat(alloc.utilized_amount),
                                                    ),
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(alloc.status)} />
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatDate(alloc.requested_at)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={allocations} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
