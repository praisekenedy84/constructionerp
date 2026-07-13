import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { formatCurrency } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, StockBalance } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface StockProps extends PageProps {
    balances: Paginated<StockBalance>;
    filters: ListingFilters & { project_id?: string };
    low_stock_count: number;
}

export default function Stock() {
    const { balances, filters, low_stock_count } = usePage<StockProps>().props;
    const rows = balances.data ?? [];

    return (
        <AppShell title="Stock">
            <Head title="Stock Balances" />
            <div className="space-y-6">
                <PageHeader
                    title="Stock Balances"
                    description={`${low_stock_count} items below reorder point`}
                />

                <ListToolbar
                    baseUrl="/inventory/balances"
                    filters={filters}
                    searchPlaceholder="Search item, code, location…"
                    sortOptions={[
                        { value: 'updated_at', label: 'Last updated' },
                        { value: 'quantity_on_hand', label: 'Quantity on hand' },
                        { value: 'average_cost', label: 'Average cost' },
                    ]}
                    textFilters={[{ key: 'project_id', label: 'Project ID', placeholder: 'Project ID' }]}
                />

                <DataPanel title="Stock by Location" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Item</th>
                                <th className="px-6 py-3 font-medium">Code</th>
                                <th className="px-6 py-3 font-medium">Location</th>
                                <th className="px-6 py-3 text-right font-medium">Qty on Hand</th>
                                <th className="px-6 py-3 text-right font-medium">Avg Cost</th>
                                <th className="px-6 py-3 text-right font-medium">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No stock balances recorded.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((bal) => (
                                    <tr key={bal.id}>
                                        <td className="px-6 py-4 font-medium">
                                            {bal.inventory_item?.name}
                                        </td>
                                        <td className="px-6 py-4 font-mono text-slate-600">
                                            {bal.inventory_item?.code}
                                        </td>
                                        <td className="px-6 py-4">{bal.location?.name}</td>
                                        <td className="px-6 py-4 text-right">
                                            {bal.quantity_on_hand} {bal.inventory_item?.unit}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {formatCurrency(bal.average_cost)}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(
                                                parseFloat(bal.quantity_on_hand) *
                                                    parseFloat(bal.average_cost),
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={balances} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
