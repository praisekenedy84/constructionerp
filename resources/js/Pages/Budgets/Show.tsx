import AppShell from '@/Components/Layout/AppShell';
import SimpleBarChart from '@/Components/Charts/SimpleBarChart';
import SimpleLineChart from '@/Components/Charts/SimpleLineChart';
import SimplePieChart from '@/Components/Charts/SimplePieChart';
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
import { formatCurrency, formatDate, formatPercent } from '@/lib/formatters';
import {
    aggregateBudgetByType,
    aggregateBudgetTimeline,
} from '@/lib/chart-helpers';
import { BudgetTransaction, ListingFilters, PageProps, Paginated, Project } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface BudgetShowProps extends PageProps {
    project: Project;
    gross_budget: string;
    ipc_deductions: string;
    ledger_spend: string;
    utilized_budget: string;
    remaining_budget: string;
    utilization_percentage: string;
    transactions: Paginated<BudgetTransaction>;
    filters: ListingFilters;
}

export default function BudgetShow() {
    const {
        project,
        gross_budget,
        ipc_deductions,
        utilized_budget,
        remaining_budget,
        utilization_percentage,
        transactions,
        filters,
    } = usePage<BudgetShowProps>().props;
    const rows = transactions.data ?? [];
    const budgetByType = aggregateBudgetByType(rows);
    const budgetTimeline = aggregateBudgetTimeline(rows);
    const budgetOverview = [
        { name: 'Utilized', amount: parseFloat(utilized_budget) || 0 },
        { name: 'Remaining', amount: parseFloat(remaining_budget) || 0 },
    ];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        amount: '',
        reason: '',
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
        reset();
        clearErrors();
    }

    function submitAdjustment(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.id}/budget/adjustment`, {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Budget Ledger">
            <Head title={`Budget — ${project.name}`} />
            <div className="space-y-6">
                <PageHeader
                    title="Budget Ledger"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Post Adjustment
                        </Button>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <DataPanel title="Phase Budget">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(gross_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="IPC Deductions">
                        <p className="text-2xl font-bold text-amber-700">
                            {formatCurrency(ipc_deductions)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Net Budget">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(project.net_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Remaining Budget">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(remaining_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Utilized">
                        <p className="text-2xl font-bold text-slate-600">
                            {formatCurrency(utilized_budget)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            {formatPercent(utilization_percentage)} of phase budget
                        </p>
                    </DataPanel>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel
                        title="Budget Allocation"
                        description="IPC deductions and later charges vs remaining budget"
                    >
                        <SimpleBarChart
                            data={budgetOverview}
                            xKey="name"
                            series={[
                                { key: 'amount', name: 'Amount (TZS)', color: '#1d4ed8' },
                            ]}
                        />
                    </DataPanel>

                    <DataPanel
                        title="Ledger Spend by Category"
                        description="Budget transactions after IPC deductions"
                    >
                        <SimplePieChart
                            data={budgetByType}
                            valueLabel="Amount"
                            formatValue={(value) => formatCurrency(value)}
                        />
                    </DataPanel>
                </div>

                <DataPanel
                    title="Cumulative Ledger Spend"
                    description="Running total of budget transactions after IPC deductions"
                >
                    <SimpleLineChart
                        data={budgetTimeline}
                        xKey="date"
                        series={[{ key: 'spent', name: 'Cumulative Spend', color: '#1d4ed8' }]}
                    />
                </DataPanel>

                <ListToolbar
                    baseUrl={`/projects/${project.id}/budget`}
                    filters={filters}
                    searchPlaceholder="Search type, reason, user…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date' },
                        { value: 'amount', label: 'Amount' },
                        { value: 'type', label: 'Type' },
                    ]}
                />

                <DataPanel title="Transaction Ledger" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 font-medium">Reason</th>
                                <th className="px-6 py-3 font-medium">By</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No transactions yet.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((tx) => (
                                    <tr key={tx.id} className="hover:bg-slate-50">
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatDate(tx.created_at)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(tx.type).toLowerCase()} />
                                        </td>
                                        <td
                                            className={`px-6 py-4 text-right font-medium ${
                                                parseFloat(tx.amount) < 0
                                                    ? 'text-red-600'
                                                    : 'text-slate-900'
                                            }`}
                                        >
                                            {formatCurrency(tx.amount)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {tx.reason ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {tx.creator?.name ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={transactions} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Manual Budget Adjustment"
                description="Requires reason. Finance Manager or MD only."
            >
                <form onSubmit={submitAdjustment} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="amount">Amount (TZS)</Label>
                        <AmountInput
                            id="amount"
                            value={data.amount}
                            onValueChange={(v) => setData('amount', v)}
                        />
                        {errors.amount && (
                            <p className="text-sm text-red-600">{errors.amount}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="reason">Reason</Label>
                        <Input
                            id="reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            required
                        />
                        {errors.reason && (
                            <p className="text-sm text-red-600">{errors.reason}</p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Post Adjustment"
                        processingLabel="Saving…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
