import AppShell from '@/Components/Layout/AppShell';
import SimpleLineChart from '@/Components/Charts/SimpleLineChart';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { aggregateCashFlowTimeline } from '@/lib/chart-helpers';
import { CashAllocation, ListingFilters, PageProps, Paginated, Project } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface CashFlowProps extends PageProps {
    project: Project;
    projects: Pick<Project, 'id' | 'code' | 'name'>[];
    allocations: Paginated<CashAllocation>;
    filters: ListingFilters;
}

export default function CashFlow() {
    const { project, projects, allocations, filters } = usePage<CashFlowProps>().props;
    const rows = allocations.data ?? [];
    const cashFlowTimeline = aggregateCashFlowTimeline(rows);
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        project_id: String(project.id),
        requested_amount: '',
        method: '',
        reference_no: '',
    });

    function openDialog() {
        clearErrors();
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        reset('requested_amount', 'method', 'reference_no');
        setData('project_id', String(project.id));
        clearErrors();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/finance/cash-requests', {
            onSuccess: () => {
                reset('requested_amount', 'method', 'reference_no');
                setData('project_id', String(project.id));
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Cash Flow">
            <Head title="Cash Flow" />
            <div className="space-y-6">
                <PageHeader
                    title="Cash Flow"
                    description={`Request and track cash allocations for ${project.name}`}
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Create New Request
                        </Button>
                    }
                />

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

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Create New Fund Request"
                description="Choose a project for project cash float, or Organization (general) for company-wide funds. Manager approves via Fund Approvals."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="cash-project">Allocate funds to</Label>
                        <select
                            id="cash-project"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.project_id}
                            onChange={(e) => setData('project_id', e.target.value)}
                        >
                            <option value="">Organization (general)</option>
                            {(projects ?? [project]).map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.code} — {p.name}
                                </option>
                            ))}
                        </select>
                        {errors.project_id && (
                            <p className="text-sm text-red-600">{errors.project_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="cash-amount">Amount (TZS)</Label>
                        <AmountInput
                            id="cash-amount"
                            value={data.requested_amount}
                            onValueChange={(v) => setData('requested_amount', v)}
                            required
                        />
                        {errors.requested_amount && (
                            <p className="text-sm text-red-600">{errors.requested_amount}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="cash-method">Method</Label>
                        <Input
                            id="cash-method"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                            placeholder="Bank transfer"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="cash-reference">Reference No</Label>
                        <Input
                            id="cash-reference"
                            value={data.reference_no}
                            onChange={(e) => setData('reference_no', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Request"
                        processingLabel="Requesting…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
