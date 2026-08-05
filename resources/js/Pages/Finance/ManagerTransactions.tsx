import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { formatCurrency, formatDate } from '@/lib/formatters';
import {
    AccountTransaction,
    ListingFilters,
    MoneyAccount,
    PageProps,
    Paginated,
} from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface ManagerTransactionsProps extends PageProps {
    accounts: MoneyAccount[];
    transactions: Paginated<AccountTransaction>;
    filters: ListingFilters & { account_id?: string };
    summary: { balance: string; account_count: number };
    can_deposit: boolean;
}

const typeLabels: Record<string, string> = {
    deposit: 'Deposit',
    transfer_out: 'Transfer to Finance',
    transfer_in: 'Transfer in',
    opening_balance: 'Opening balance',
    adjustment: 'Adjustment',
    disbursement: 'Disbursement',
};

export default function ManagerTransactions() {
    const { accounts, transactions, filters, summary } =
        usePage<ManagerTransactionsProps>().props;
    const rows = transactions.data ?? [];

    return (
        <AppShell title="Manager Transactions">
            <Head title="Manager Transactions" />
            <div className="space-y-6">
                <PageHeader
                    title="Manager Transactions"
                    description="Deposits into manager accounts and transfers out when fund requests are approved."
                    actions={
                        <Link href="/finance/accounts">
                            <span className="text-sm text-blue-700 hover:underline">Manage accounts</span>
                        </Link>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Total manager balance</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900">
                            {formatCurrency(summary.balance)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Accounts</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900">{summary.account_count}</p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/finance/manager-transactions"
                    filters={filters}
                    searchPlaceholder="Search description or reference…"
                    sortOptions={[
                        { value: 'occurred_at', label: 'Date' },
                        { value: 'amount', label: 'Amount' },
                        { value: 'type', label: 'Type' },
                    ]}
                    selectFilters={[
                        {
                            key: 'account_id',
                            label: 'Account',
                            emptyLabel: 'All manager accounts',
                            options: accounts.map((a) => ({
                                value: String(a.id),
                                label: `${a.name} (${formatCurrency(a.balance)})`,
                            })),
                        },
                    ]}
                />

                <DataPanel title={`Transactions (${transactions.total})`} noPadding>
                    {rows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No manager transactions yet.
                        </p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                            <th className="px-4 py-3 font-medium">Date</th>
                                            <th className="px-4 py-3 font-medium">Account</th>
                                            <th className="px-4 py-3 font-medium">Type</th>
                                            <th className="px-4 py-3 font-medium">Description</th>
                                            <th className="px-4 py-3 text-right font-medium">Amount</th>
                                            <th className="px-4 py-3 text-right font-medium">Balance after</th>
                                            <th className="px-4 py-3 font-medium">Recorded by</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {rows.map((tx) => (
                                            <tr key={tx.id}>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {tx.occurred_at
                                                        ? formatDate(tx.occurred_at)
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-slate-900">
                                                    {tx.account?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {typeLabels[tx.type] ?? tx.type}
                                                    {tx.related_account && (
                                                        <p className="text-xs text-slate-400">
                                                            ↔ {tx.related_account.name}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {tx.description ?? '—'}
                                                    {tx.reference_no && (
                                                        <p className="text-xs text-slate-400">
                                                            Ref: {tx.reference_no}
                                                        </p>
                                                    )}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right font-medium ${
                                                        tx.is_credit ? 'text-green-700' : 'text-red-700'
                                                    }`}
                                                >
                                                    {tx.is_credit ? '+' : '−'}
                                                    {formatCurrency(tx.amount)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-slate-600">
                                                    {formatCurrency(tx.balance_after)}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {tx.recorder?.name ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationLinks paginator={transactions} />
                        </>
                    )}
                </DataPanel>
            </div>
        </AppShell>
    );
}
