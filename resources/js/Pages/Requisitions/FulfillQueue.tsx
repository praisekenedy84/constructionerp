import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PermissionDenied from '@/Components/Shared/PermissionDenied';
import { LinkButton } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatCurrency } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { ListingFilters, PageProps, Paginated, Requisition } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface FulfillQueueProps extends PageProps {
    requisitions: Paginated<Requisition>;
    filters: ListingFilters & { fulfillment_type?: string };
}

const fulfillmentTypeOptions = [
    { value: 'cash_disbursement', label: 'Cash disbursement' },
    { value: 'stock_issue', label: 'Stock issue' },
    { value: 'direct_supplier_payment', label: 'Direct supplier payment' },
];

export default function FulfillQueue() {
    const { requisitions, filters, auth } = usePage<FulfillQueueProps>().props;
    const rows = requisitions.data ?? [];

    if (!hasPermission(auth.user, 'requisitions', 'fulfill')) {
        return (
            <AppShell title="Fulfill Queue">
                <Head title="Fulfill Queue" />
                <div className="flex min-h-[50vh] items-center justify-center px-4 py-10">
                    <PermissionDenied
                        message="You do not have permission to fulfill requisitions."
                        backHref="/requisitions"
                        backLabel="Back to requisitions"
                    />
                </div>
            </AppShell>
        );
    }

    return (
        <AppShell title="Fulfill Queue">
            <Head title="Fulfill Queue" />
            <div className="space-y-6">
                <PageHeader
                    title="Fulfillment Queue"
                    description="Approved requisitions awaiting stock issue or cash disbursement."
                />

                <ListToolbar
                    baseUrl="/requisitions/fulfill-queue"
                    filters={filters}
                    searchPlaceholder="Search requisition no, department…"
                    sortOptions={[
                        { value: 'requisition_no', label: 'Requisition no' },
                        { value: 'updated_at', label: 'Updated date' },
                        { value: 'original_amount', label: 'Amount' },
                        { value: 'fulfillment_type', label: 'Fulfillment type' },
                    ]}
                    selectFilters={[
                        {
                            key: 'fulfillment_type',
                            label: 'Fulfillment type',
                            emptyLabel: 'All fulfillment types',
                            options: fulfillmentTypeOptions,
                        },
                    ]}
                />

                <DataPanel title={`Awaiting Fulfillment (${requisitions.total})`} noPadding>
                    {rows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No requisitions awaiting fulfillment.
                        </p>
                    ) : (
                        <>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                        <th className="px-6 py-3 font-medium">Requisition</th>
                                        <th className="px-6 py-3 font-medium">Project</th>
                                        <th className="px-6 py-3 font-medium">Type</th>
                                        <th className="px-6 py-3 font-medium">Status</th>
                                        <th className="px-6 py-3 text-right font-medium">Amount</th>
                                        <th className="px-6 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {rows.map((req) => (
                                        <tr key={req.id}>
                                            <td className="px-6 py-4 font-mono text-slate-900">
                                                {req.requisition_no}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600">
                                                {req.project?.name ?? '—'}
                                            </td>
                                            <td className="px-6 py-4 capitalize text-slate-600">
                                                {String(req.fulfillment_type).replace(/_/g, ' ')}
                                            </td>
                                            <td className="px-6 py-4">
                                                <StatusBadge status={String(req.status)} />
                                            </td>
                                            <td className="px-6 py-4 text-right font-medium">
                                                {formatCurrency(req.amended_amount ?? req.original_amount)}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <LinkButton href={`/requisitions/${req.id}`}>
                                                    Fulfill
                                                </LinkButton>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <PaginationLinks paginator={requisitions} />
                        </>
                    )}
                </DataPanel>
            </div>
        </AppShell>
    );
}
