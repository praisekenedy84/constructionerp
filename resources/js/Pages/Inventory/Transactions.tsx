import AppShell from '@/Components/Layout/AppShell';
import InventoryNav from '@/Components/Inventory/InventoryNav';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatCurrency, formatDate, formatQuantity } from '@/lib/formatters';
import { InventoryTransaction, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface TransactionsProps extends PageProps {
    transactions: Paginated<InventoryTransaction>;
    filters: ListingFilters;
}

export default function Transactions() {
    const { transactions, filters } = usePage<TransactionsProps>().props;
    const rows = transactions.data ?? [];

    return (
        <AppShell title="History">
            <Head title="Stock History" />
            <div className="space-y-6">
                <PageHeader
                    title="4. History"
                    description="Full ledger of receives, handovers, transfers, and corrections."
                />
                <InventoryNav active="transactions" />

                <ListToolbar
                    baseUrl="/inventory/transactions"
                    filters={filters}
                    searchPlaceholder="Search item, type, location…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date' },
                        { value: 'quantity', label: 'Quantity' },
                        { value: 'unit_cost', label: 'Unit cost' },
                        { value: 'type', label: 'Type' },
                    ]}
                />

                <DataPanel title="Movement ledger" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Item</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 text-right font-medium">Qty</th>
                                <th className="px-6 py-3 text-right font-medium">Unit cost</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No movements yet. Activity appears here after you receive, transfer, or hand
                                        over stock.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((tx) => (
                                    <tr key={tx.id}>
                                        <td className="px-6 py-4">{formatDate(tx.created_at)}</td>
                                        <td className="px-6 py-4">{tx.inventory_item?.name}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={tx.type} />
                                        </td>
                                        <td className="px-6 py-4 text-right">{formatQuantity(tx.quantity)}</td>
                                        <td className="px-6 py-4 text-right">
                                            {tx.unit_cost ? formatCurrency(tx.unit_cost) : '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={transactions} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
