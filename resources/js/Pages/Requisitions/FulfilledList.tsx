import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { IconLink } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatCurrency, formatDate, formatQuantity } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, Requisition } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

interface FulfilledListProps extends PageProps {
    requisitions: Paginated<Requisition>;
    filters: ListingFilters & {
        fulfillment_type?: string;
        status?: string;
    };
}

const fulfillmentTypeOptions = [
    { value: 'cash_disbursement', label: 'Cash disbursement' },
    { value: 'stock_issue', label: 'Stock issue' },
    { value: 'direct_supplier_payment', label: 'Direct supplier payment' },
];

const statusOptions = [
    { value: 'fulfilled', label: 'Fulfilled' },
    { value: 'closed', label: 'Closed' },
];

function lineSummary(req: Requisition): string {
    const items = req.items ?? [];
    if (items.length === 0) {
        return 'No lines';
    }

    const first = items[0]?.description ?? 'Item';
    if (items.length === 1) {
        return first;
    }

    return `${first} +${items.length - 1} more`;
}

export default function FulfilledList() {
    const { requisitions, filters } = usePage<FulfilledListProps>().props;
    const rows = requisitions.data ?? [];

    return (
        <AppShell title="Fulfilled List">
            <Head title="Fulfilled List" />
            <div className="space-y-6">
                <PageHeader
                    title="Fulfilled List"
                    description="Fulfilled and closed requisitions with requestor, category, and line details."
                />

                <ListToolbar
                    baseUrl="/requisitions/fulfilled"
                    filters={filters}
                    searchPlaceholder="Search requisition no, department, requestor…"
                    sortOptions={[
                        { value: 'updated_at', label: 'Updated date' },
                        { value: 'requisition_no', label: 'Requisition no' },
                        { value: 'original_amount', label: 'Amount' },
                        { value: 'fulfillment_type', label: 'Fulfillment type' },
                        { value: 'status', label: 'Status' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'Fulfilled & closed',
                            options: statusOptions,
                        },
                        {
                            key: 'fulfillment_type',
                            label: 'Fulfillment type',
                            emptyLabel: 'All fulfillment types',
                            options: fulfillmentTypeOptions,
                        },
                    ]}
                />

                <DataPanel title={`Fulfilled (${requisitions.total})`} noPadding>
                    {rows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No fulfilled requisitions found.
                        </p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="min-w-[1200px] w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                            <th className="px-4 py-3 font-medium">Req No</th>
                                            <th className="px-4 py-3 font-medium">Date</th>
                                            <th className="px-4 py-3 font-medium">Requested By</th>
                                            <th className="px-4 py-3 font-medium">Department</th>
                                            <th className="px-4 py-3 font-medium">Project</th>
                                            <th className="px-4 py-3 font-medium">Category</th>
                                            <th className="px-4 py-3 font-medium">Lines</th>
                                            <th className="px-4 py-3 font-medium">Fulfillment</th>
                                            <th className="px-4 py-3 font-medium">Status</th>
                                            <th className="px-4 py-3 text-right font-medium">Amount</th>
                                            <th className="px-4 py-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {rows.map((req) => {
                                            const items = req.items ?? [];

                                            return (
                                                <tr key={req.id} className="align-top hover:bg-slate-50">
                                                    <td className="whitespace-nowrap px-4 py-3 font-mono text-slate-900">
                                                        {req.requisition_no}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">
                                                        {formatDate(req.updated_at)}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-700">
                                                        <div>{req.requestor?.name ?? 'Unknown'}</div>
                                                        {req.requestor?.email && (
                                                            <div className="text-xs text-slate-500">
                                                                {req.requestor.email}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-600">
                                                        {req.department}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-600">
                                                        <div className="font-mono text-xs text-slate-500">
                                                            {req.project?.code ?? 'ORG'}
                                                        </div>
                                                        <div>{req.project?.name ?? 'Organization'}</div>
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-600">
                                                        {req.category?.name ??
                                                            String(req.resource_type ?? '—').replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-700">
                                                        <div className="max-w-[220px]">
                                                            <p className="truncate text-slate-800">
                                                                {lineSummary(req)}
                                                            </p>
                                                            {items.length > 0 && (
                                                                <ul className="mt-1 space-y-0.5 text-xs text-slate-500">
                                                                    {items.slice(0, 3).map((item) => (
                                                                        <li
                                                                            key={item.id}
                                                                            className="flex justify-between gap-2"
                                                                        >
                                                                            <span className="truncate">
                                                                                {item.description}
                                                                            </span>
                                                                            <span className="shrink-0 tabular-nums">
                                                                                {formatQuantity(
                                                                                    item.quantity,
                                                                                )}{' '}
                                                                                {item.unit ?? ''} ·{' '}
                                                                                {formatCurrency(
                                                                                    item.line_total,
                                                                                )}
                                                                            </span>
                                                                        </li>
                                                                    ))}
                                                                    {items.length > 3 && (
                                                                        <li>
                                                                            +{items.length - 3} more
                                                                            lines
                                                                        </li>
                                                                    )}
                                                                </ul>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 capitalize text-slate-600">
                                                        <div>
                                                            {String(req.fulfillment_type).replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-slate-500 capitalize">
                                                            {String(req.addressed_to ?? '—').replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <StatusBadge status={String(req.status)} />
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                                        <div className="font-medium tabular-nums text-slate-900">
                                                            {formatCurrency(
                                                                req.amended_amount ??
                                                                    req.original_amount,
                                                            )}
                                                        </div>
                                                        {req.amended_amount != null && (
                                                            <div className="text-xs tabular-nums text-slate-500">
                                                                Orig.{' '}
                                                                {formatCurrency(req.original_amount)}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <IconLink
                                                            href={`/requisitions/${req.id}`}
                                                            icon={Eye}
                                                            label="View requisition"
                                                        />
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationLinks paginator={requisitions} />
                        </>
                    )}
                </DataPanel>
            </div>
        </AppShell>
    );
}
