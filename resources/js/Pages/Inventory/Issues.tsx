import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { InventoryIssue, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface IssuesProps extends PageProps {
    issues: Paginated<InventoryIssue>;
    filters: ListingFilters;
}

export default function Issues() {
    const { issues, filters } = usePage<IssuesProps>().props;
    const rows = issues.data ?? [];

    return (
        <AppShell title="Inventory Issues">
            <Head title="Inventory Issues" />
            <div className="space-y-6">
                <PageHeader
                    title="Inventory Issues"
                    description="Stock issued against requisitions."
                />

                <ListToolbar
                    baseUrl="/inventory/issues"
                    filters={filters}
                    searchPlaceholder="Search item, requisition, location…"
                    sortOptions={[
                        { value: 'issued_at', label: 'Issued date' },
                        { value: 'quantity', label: 'Quantity' },
                        { value: 'value', label: 'Value' },
                    ]}
                />

                <DataPanel title="Issue History" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Item</th>
                                <th className="px-6 py-3 font-medium">Requisition</th>
                                <th className="px-6 py-3 font-medium">Qty</th>
                                <th className="px-6 py-3 text-right font-medium">Value</th>
                                <th className="px-6 py-3 font-medium">Issued</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No issues found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((issue) => (
                                    <tr key={issue.id}>
                                        <td className="px-6 py-4">{issue.inventory_item?.name}</td>
                                        <td className="px-6 py-4 font-mono">
                                            {issue.requisition?.requisition_no ?? `#${issue.requisition_id}`}
                                        </td>
                                        <td className="px-6 py-4">{issue.quantity}</td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(issue.value)}
                                        </td>
                                        <td className="px-6 py-4">{formatDate(issue.issued_at)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={issues} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
