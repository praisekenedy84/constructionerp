import AppShell from '@/Components/Layout/AppShell';
import SimpleBarChart from '@/Components/Charts/SimpleBarChart';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { formatCurrency } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { CashAllocation, PageProps, Paginated, Project, ReconciliationSummary } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface FinanceIndexProps extends PageProps {
    project: Project;
    reconciliation: ReconciliationSummary;
    recent_allocations: Paginated<CashAllocation>;
}

export default function FinanceIndex() {
    const { project, reconciliation, recent_allocations, auth } =
        usePage<FinanceIndexProps>().props;
    const canApproveFunds = hasPermission(auth.user, 'budgets', 'approve');
    const allocationRows = recent_allocations.data ?? [];

    const reconciliationChart = [
        { name: 'Committed', amount: parseFloat(reconciliation.committed) || 0 },
        { name: 'Disbursed', amount: parseFloat(reconciliation.disbursed) || 0 },
        { name: 'Outstanding', amount: parseFloat(reconciliation.outstanding) || 0 },
        { name: 'Cash on Hand', amount: parseFloat(reconciliation.cash_on_hand) || 0 },
    ];

    return (
        <AppShell title="Finance">
            <Head title="Finance" />
            <div className="space-y-6">
                <PageHeader
                    title="Finance Dashboard"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        canApproveFunds ? (
                            <Link href="/finance/approvals">
                                <span className="inline-flex h-10 items-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                    Fund Approvals
                                </span>
                            </Link>
                        ) : undefined
                    }
                />

                <div className="grid gap-4 sm:grid-cols-4">
                    <DataPanel title="Committed">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(reconciliation.committed)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Disbursed">
                        <p className="text-2xl font-bold text-slate-600">
                            {formatCurrency(reconciliation.disbursed)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Outstanding">
                        <p className="text-2xl font-bold text-amber-700">
                            {formatCurrency(reconciliation.outstanding)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Cash on Hand">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(reconciliation.cash_on_hand)}
                        </p>
                    </DataPanel>
                </div>

                <DataPanel
                    title="Cash Position Overview"
                    description="Committed, disbursed, outstanding, and available cash"
                >
                    <SimpleBarChart
                        data={reconciliationChart}
                        xKey="name"
                        series={[{ key: 'amount', name: 'Amount (TZS)', color: '#1d4ed8' }]}
                    />
                </DataPanel>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        href={`/finance/${project.id}/cash-flow`}
                        className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-300"
                    >
                        <p className="font-medium text-slate-900">Cash Flow</p>
                        <p className="mt-1 text-xs text-slate-500">Allocations and disbursements</p>
                    </Link>
                    <Link
                        href={`/finance/reconciliation/${project.id}`}
                        className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-300"
                    >
                        <p className="font-medium text-slate-900">Reconciliation</p>
                        <p className="mt-1 text-xs text-slate-500">Committed vs disbursed</p>
                    </Link>
                    <Link
                        href="/finance/expenses"
                        className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-300"
                    >
                        <p className="font-medium text-slate-900">Direct Expenses</p>
                        <p className="mt-1 text-xs text-slate-500">Project cost postings</p>
                    </Link>
                    <Link
                        href="/finance/overhead"
                        className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-300"
                    >
                        <p className="font-medium text-slate-900">Overhead</p>
                        <p className="mt-1 text-xs text-slate-500">Indirect company expenses</p>
                    </Link>
                </div>

                <DataPanel title="Cash Allocations" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Requested</th>
                                <th className="px-6 py-3 font-medium">Received</th>
                                <th className="px-6 py-3 font-medium">Utilized</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {allocationRows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-8 text-center text-slate-500">
                                        No cash allocations yet.
                                    </td>
                                </tr>
                            ) : (
                                allocationRows.map((alloc) => (
                                    <tr key={alloc.id}>
                                        <td className="px-6 py-3">
                                            {formatCurrency(alloc.requested_amount)}
                                        </td>
                                        <td className="px-6 py-3">
                                            {formatCurrency(alloc.received_amount)}
                                        </td>
                                        <td className="px-6 py-3">
                                            {formatCurrency(alloc.utilized_amount)}
                                        </td>
                                        <td className="px-6 py-3 capitalize">{alloc.status}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={recent_allocations} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
