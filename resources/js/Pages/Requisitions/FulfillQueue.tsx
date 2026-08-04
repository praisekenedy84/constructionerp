import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PermissionDenied from '@/Components/Shared/PermissionDenied';
import { LinkButton } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatCurrency, formatQuantity } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { ListingFilters, PageProps, Paginated, Requisition } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface FulfillQueueProps extends PageProps {
    requisitions: Paginated<Requisition>;
    filters: ListingFilters & {
        fulfillment_type?: string;
        requisition_id?: string;
    };
    focusRequisitionId?: number | null;
}

const fulfillmentTypeOptions = [
    { value: 'cash_disbursement', label: 'Cash disbursement' },
    { value: 'stock_issue', label: 'Stock issue' },
    { value: 'direct_supplier_payment', label: 'Direct supplier payment' },
];

export default function FulfillQueue() {
    const { requisitions, filters, auth, focusRequisitionId } =
        usePage<FulfillQueueProps>().props;
    const rows = requisitions.data ?? [];
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = rows.find((req) => req.id === selectedId) ?? null;

    useEffect(() => {
        const currentRows = requisitions.data ?? [];
        if (focusRequisitionId) {
            const match = currentRows.find((req) => req.id === focusRequisitionId);
            if (match) {
                setSelectedId(match.id);
                return;
            }
        }
        setSelectedId(currentRows.length > 0 ? currentRows[0].id : null);
    }, [requisitions.current_page, requisitions.total, requisitions.data, focusRequisitionId]);

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
                    description="Review request details, then open the requisition to disburse cash or issue stock."
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

                <div className="grid gap-6 lg:grid-cols-3">
                    <DataPanel title={`Awaiting Fulfillment (${requisitions.total})`} noPadding>
                        {rows.length === 0 ? (
                            <p className="px-6 py-12 text-center text-sm text-slate-500">
                                No requisitions awaiting fulfillment.
                            </p>
                        ) : (
                            <>
                                <ul className="divide-y divide-slate-100">
                                    {rows.map((req) => (
                                        <li key={req.id}>
                                            <button
                                                type="button"
                                                onClick={() => setSelectedId(req.id)}
                                                className={`w-full px-4 py-3 text-left hover:bg-slate-50 ${
                                                    selectedId === req.id ? 'bg-blue-50' : ''
                                                }`}
                                            >
                                                <p className="font-mono text-sm font-medium text-slate-900">
                                                    {req.requisition_no}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {req.project?.name ?? 'Organization'} ·{' '}
                                                    {String(req.fulfillment_type).replace(/_/g, ' ')}
                                                </p>
                                                <p className="text-xs text-slate-600">
                                                    From: {req.requestor?.name ?? 'Unknown'}
                                                </p>
                                                <p className="mt-1 text-sm font-medium text-slate-700">
                                                    {formatCurrency(
                                                        req.amended_amount ?? req.original_amount,
                                                    )}
                                                </p>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                                <PaginationLinks paginator={requisitions} />
                            </>
                        )}
                    </DataPanel>

                    <div className="space-y-4 lg:col-span-2">
                        {selected ? (
                            <>
                                <DataPanel title={selected.requisition_no}>
                                    <div className="mb-4 flex flex-wrap items-center gap-3">
                                        <StatusBadge status={String(selected.status)} />
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs capitalize text-slate-600">
                                            {String(selected.fulfillment_type).replace(/_/g, ' ')}
                                        </span>
                                        <LinkButton
                                            href={`/requisitions/${selected.id}`}
                                            className="ml-auto"
                                        >
                                            Open to fulfill
                                        </LinkButton>
                                    </div>

                                    <dl className="mb-4 grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <dt className="text-xs text-slate-500">Requested by</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {selected.requestor?.name ?? 'Unknown'}
                                            </dd>
                                            {selected.requestor?.email && (
                                                <dd className="text-xs text-slate-500">
                                                    {selected.requestor.email}
                                                </dd>
                                            )}
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">Department</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {selected.department}
                                            </dd>
                                        </div>
                                        {((selected.recipients?.length ?? 0) > 0 ||
                                            selected.recipient_name ||
                                            selected.recipient_position) && (
                                            <div className="sm:col-span-2">
                                                <dt className="text-xs text-slate-500">
                                                    On behalf of
                                                </dt>
                                                <dd className="text-sm font-medium text-slate-900">
                                                    {(selected.recipients?.length ?? 0) > 0
                                                        ? selected.recipients
                                                              ?.map((r) =>
                                                                  [
                                                                      r.name === '—'
                                                                          ? null
                                                                          : r.name,
                                                                      r.position_name,
                                                                  ]
                                                                      .filter(Boolean)
                                                                      .join(' · '),
                                                              )
                                                              .filter(Boolean)
                                                              .join('; ')
                                                        : `${selected.recipient_name || '—'}${
                                                              selected.recipient_position
                                                                  ? ` · ${selected.recipient_position}`
                                                                  : ''
                                                          }`}
                                                </dd>
                                            </div>
                                        )}
                                        <div>
                                            <dt className="text-xs text-slate-500">Project</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {selected.project?.name ?? '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">Category</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {(selected.categories?.length ?? 0) > 0
                                                    ? selected.categories
                                                          ?.map((c) => c.name)
                                                          .join(', ')
                                                    : selected.category?.name ??
                                                      String(selected.resource_type ?? '—').replace(
                                                          /_/g,
                                                          ' ',
                                                      )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">Addressed to</dt>
                                            <dd className="text-sm font-medium capitalize text-slate-900">
                                                {String(selected.addressed_to ?? '—').replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">Amount</dt>
                                            <dd className="text-sm font-semibold text-slate-900">
                                                {formatCurrency(
                                                    selected.amended_amount ??
                                                        selected.original_amount,
                                                )}
                                            </dd>
                                        </div>
                                    </dl>

                                    {(selected.items ?? []).length > 0 && (
                                        <div className="overflow-hidden rounded-lg border border-slate-200">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                                        <th className="px-3 py-2 font-medium">
                                                            Description
                                                        </th>
                                                        <th className="px-3 py-2 font-medium">Unit</th>
                                                        <th className="px-3 py-2 text-right font-medium">
                                                            Qty
                                                        </th>
                                                        <th className="px-3 py-2 text-right font-medium">
                                                            Rate
                                                        </th>
                                                        <th className="px-3 py-2 text-right font-medium">
                                                            Amount
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {(selected.items ?? []).map((item) => (
                                                        <tr key={item.id}>
                                                            <td className="px-3 py-2 text-slate-800">
                                                                {item.description}
                                                            </td>
                                                            <td className="px-3 py-2 text-slate-600">
                                                                {item.unit ?? '—'}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-slate-700">
                                                                {formatQuantity(item.quantity)}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums text-slate-700">
                                                                {formatCurrency(item.unit_cost)}
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-medium tabular-nums text-slate-900">
                                                                {formatCurrency(item.line_total)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </DataPanel>

                                <DataPanel
                                    title="Next step"
                                    description="Use Open to fulfill to record cash disbursement or stock issue on the full requisition page."
                                >
                                    <LinkButton href={`/requisitions/${selected.id}`}>
                                        Open to fulfill
                                    </LinkButton>
                                </DataPanel>
                            </>
                        ) : (
                            <DataPanel title="Request details">
                                <p className="py-8 text-center text-sm text-slate-500">
                                    Select a requisition from the queue to review its details.
                                </p>
                            </DataPanel>
                        )}
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
