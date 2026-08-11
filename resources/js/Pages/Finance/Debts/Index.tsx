import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PageHeader from '@/Components/Shared/PageHeader';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import {
    CompanyDebt,
    ListingFilters,
    PageProps,
    Paginated,
} from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

interface DebtsIndexProps extends PageProps {
    debts: Paginated<CompanyDebt>;
    filters: ListingFilters & { status?: string; type?: string };
    summary: { open_outstanding: string; open_count: number };
    can_repay: boolean;
    status_options: { value: string; label: string }[];
    type_options: { value: string; label: string }[];
}

export default function DebtsIndex() {
    const { debts, filters, summary, status_options, type_options } =
        usePage<DebtsIndexProps>().props;
    const rows = debts.data ?? [];

    return (
        <AppShell title="Debts">
            <Head title="Debts" />
            <div className="space-y-6">
                <PageHeader
                    title="Debts"
                    description="Track company liabilities from loan and customer-advance deposits. Record repayments to clear outstanding balances."
                    actions={
                        <Link href="/finance/accounts">
                            <span className="text-sm text-blue-700 hover:underline">
                                Deposit to accounts
                            </span>
                        </Link>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Open outstanding</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900">
                            {formatCurrency(summary.open_outstanding)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Open debts</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900">{summary.open_count}</p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/finance/debts"
                    filters={filters}
                    searchPlaceholder="Search creditor or notes…"
                    sortOptions={[
                        { value: 'occurred_at', label: 'Date' },
                        { value: 'outstanding_amount', label: 'Outstanding' },
                        { value: 'original_amount', label: 'Original amount' },
                        { value: 'creditor_name', label: 'Creditor' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: status_options,
                        },
                        {
                            key: 'type',
                            label: 'Type',
                            emptyLabel: 'All types',
                            options: type_options,
                        },
                    ]}
                />

                <DataPanel title={`Debts (${debts.total})`} noPadding>
                    {rows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No debts yet. Loan and customer-advance deposits create debt records
                            automatically.
                        </p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                            <th className="px-4 py-3 font-medium">Date</th>
                                            <th className="px-4 py-3 font-medium">Creditor</th>
                                            <th className="px-4 py-3 font-medium">Type</th>
                                            <th className="px-4 py-3 font-medium">Account</th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Original
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Outstanding
                                            </th>
                                            <th className="px-4 py-3 font-medium">Status</th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {rows.map((debt) => (
                                            <tr key={debt.id} className="hover:bg-slate-50">
                                                <td className="px-4 py-3 text-slate-600">
                                                    {debt.occurred_at
                                                        ? formatDate(debt.occurred_at)
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-slate-900">
                                                    {debt.creditor_name}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {debt.type_label}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {debt.money_account?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right text-slate-900">
                                                    {formatCurrency(debt.original_amount)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium text-slate-900">
                                                    {formatCurrency(debt.outstanding_amount)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge status={debt.status} />
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/finance/debts/${debt.id}`}>
                                                        <Button size="sm" variant="outline">
                                                            <Eye className="mr-1 h-3.5 w-3.5" />
                                                            View
                                                        </Button>
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationLinks paginator={debts} />
                        </>
                    )}
                </DataPanel>
            </div>
        </AppShell>
    );
}
