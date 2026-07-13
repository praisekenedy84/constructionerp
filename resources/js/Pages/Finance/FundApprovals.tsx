import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { formatCurrency } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { CashAllocation, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';

interface FundApprovalsProps extends PageProps {
    allocations: Paginated<CashAllocation>;
    filters: ListingFilters & { status?: string };
    summary: {
        total: number;
        pending: number;
        approved: number;
        received: number;
        rejected: number;
    };
}

const statusOptions = [
    { value: 'all', label: 'All requests' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'received', label: 'Received' },
    { value: 'rejected', label: 'Rejected' },
];

export default function FundApprovals() {
    const { allocations, auth, filters, summary } = usePage<FundApprovalsProps>().props;
    const rows = allocations.data ?? [];
    const [rejectingId, setRejectingId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [receivingId, setReceivingId] = useState<number | null>(null);
    const [receiveAmount, setReceiveAmount] = useState('');
    const [receiveMethod, setReceiveMethod] = useState('');
    const [receiveReference, setReceiveReference] = useState('');

    const canApprove = hasPermission(auth.user, 'budgets', 'approve');
    const canReject = hasPermission(auth.user, 'budgets', 'reject');
    const canReceive = hasPermission(auth.user, 'budgets', 'update');

    function exportReport(format: 'xlsx' | 'pdf') {
        const params = new URLSearchParams({ format });
        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params.set(key, value);
            }
        });
        window.open(`/finance/approvals/export?${params.toString()}`, '_blank');
    }

    function approve(id: number) {
        router.post(`/finance/cash-requests/${id}/approve`);
    }

    function reject(id: number) {
        router.post(
            `/finance/cash-requests/${id}/reject`,
            { reason: rejectReason },
            {
                onSuccess: () => {
                    setRejectingId(null);
                    setRejectReason('');
                },
            },
        );
    }

    function receive(id: number) {
        router.post(
            `/finance/cash-requests/${id}/receive`,
            {
                received_amount: receiveAmount,
                method: receiveMethod || undefined,
                reference_no: receiveReference || undefined,
            },
            {
                onSuccess: () => {
                    setReceivingId(null);
                    setReceiveAmount('');
                    setReceiveMethod('');
                    setReceiveReference('');
                },
            },
        );
    }

    return (
        <AppShell title="Fund Approvals">
            <Head title="Fund Approvals" />
            <div className="space-y-6">
                <PageHeader
                    title="Fund Approvals"
                    description="Review all fund requests — pending, approved, received, and rejected — with full audit history."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={() => exportReport('xlsx')}>
                                <FileSpreadsheet className="mr-2 h-4 w-4" />
                                Export Excel
                            </Button>
                            <Button variant="outline" onClick={() => exportReport('pdf')}>
                                <Download className="mr-2 h-4 w-4" />
                                Export PDF
                            </Button>
                            <Link href="/finance">
                                <Button variant="outline">Back to Finance</Button>
                            </Link>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-5">
                    {[
                        { label: 'Total', value: summary.total, tone: 'text-slate-900' },
                        { label: 'Pending', value: summary.pending, tone: 'text-amber-700' },
                        { label: 'Approved', value: summary.approved, tone: 'text-blue-700' },
                        { label: 'Received', value: summary.received, tone: 'text-green-700' },
                        { label: 'Rejected', value: summary.rejected, tone: 'text-red-700' },
                    ].map((item) => (
                        <div
                            key={item.label}
                            className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <p className="text-xs text-slate-500">{item.label}</p>
                            <p className={`mt-1 text-2xl font-bold ${item.tone}`}>{item.value}</p>
                        </div>
                    ))}
                </div>

                <ListToolbar
                    baseUrl="/finance/approvals"
                    filters={filters}
                    searchPlaceholder="Search reference, project, requester…"
                    sortOptions={[
                        { value: 'requested_at', label: 'Requested date' },
                        { value: 'status', label: 'Status' },
                        { value: 'requested_amount', label: 'Requested amount' },
                        { value: 'received_amount', label: 'Received amount' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All requests',
                            options: statusOptions,
                        },
                    ]}
                />

                <DataPanel title={`Fund Requests (${allocations.total})`} noPadding>
                    {rows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No fund requests match this filter.
                        </p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[1100px] text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                            <th className="px-4 py-3 font-medium">ID</th>
                                            <th className="px-4 py-3 font-medium">Project</th>
                                            <th className="px-4 py-3 font-medium">Requester</th>
                                            <th className="px-4 py-3 font-medium">Status</th>
                                            <th className="px-4 py-3 text-right font-medium">Requested</th>
                                            <th className="px-4 py-3 text-right font-medium">Received</th>
                                            <th className="px-4 py-3 font-medium">Dates</th>
                                            <th className="px-4 py-3 font-medium">Approver</th>
                                            <th className="px-4 py-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {rows.map((allocation) => (
                                            <tr key={allocation.id}>
                                                <td className="px-4 py-4 font-mono text-slate-900">
                                                    #{allocation.id}
                                                </td>
                                                <td className="px-4 py-4 text-slate-600">
                                                    <p className="font-medium text-slate-900">
                                                        {allocation.project?.code ?? '—'}
                                                    </p>
                                                    <p className="text-xs">{allocation.project?.name ?? '—'}</p>
                                                </td>
                                                <td className="px-4 py-4 text-slate-600">
                                                    {allocation.requester?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-4">
                                                    <StatusBadge status={allocation.status} />
                                                </td>
                                                <td className="px-4 py-4 text-right font-medium">
                                                    {formatCurrency(allocation.requested_amount)}
                                                </td>
                                                <td className="px-4 py-4 text-right text-slate-600">
                                                    {formatCurrency(allocation.received_amount)}
                                                </td>
                                                <td className="px-4 py-4 text-xs text-slate-500">
                                                    <p>
                                                        Req:{' '}
                                                        {new Date(allocation.requested_at).toLocaleString()}
                                                    </p>
                                                    {allocation.decided_at && (
                                                        <p>
                                                            Decided:{' '}
                                                            {new Date(allocation.decided_at).toLocaleString()}
                                                        </p>
                                                    )}
                                                    {allocation.received_at && (
                                                        <p>
                                                            Received:{' '}
                                                            {new Date(allocation.received_at).toLocaleString()}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-4 text-slate-600">
                                                    <p>{allocation.approver?.name ?? '—'}</p>
                                                    {allocation.rejection_reason && (
                                                        <p className="mt-1 text-xs text-red-600">
                                                            {allocation.rejection_reason}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-4">
                                                    <div className="flex flex-col items-end gap-2">
                                                        {allocation.status === 'pending' && canApprove && (
                                                            <div className="flex gap-2">
                                                                <Button
                                                                    size="sm"
                                                                    className="bg-green-700 hover:bg-green-800"
                                                                    onClick={() => approve(allocation.id)}
                                                                >
                                                                    Approve
                                                                </Button>
                                                                {canReject && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="border-red-300 text-red-700"
                                                                        onClick={() =>
                                                                            setRejectingId(
                                                                                rejectingId === allocation.id
                                                                                    ? null
                                                                                    : allocation.id,
                                                                            )
                                                                        }
                                                                    >
                                                                        Reject
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        )}

                                                        {allocation.status === 'approved' && canReceive && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => {
                                                                    setReceivingId(
                                                                        receivingId === allocation.id
                                                                            ? null
                                                                            : allocation.id,
                                                                    );
                                                                    setReceiveAmount(allocation.requested_amount);
                                                                }}
                                                            >
                                                                Record receipt
                                                            </Button>
                                                        )}

                                                        {allocation.status !== 'pending' &&
                                                            allocation.status !== 'approved' && (
                                                                <span className="text-xs text-slate-400">
                                                                    No actions
                                                                </span>
                                                            )}

                                                        {rejectingId === allocation.id && (
                                                            <div className="flex w-full max-w-xs flex-col gap-2">
                                                                <Input
                                                                    placeholder="Rejection reason"
                                                                    value={rejectReason}
                                                                    onChange={(e) =>
                                                                        setRejectReason(e.target.value)
                                                                    }
                                                                />
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() => reject(allocation.id)}
                                                                >
                                                                    Confirm reject
                                                                </Button>
                                                            </div>
                                                        )}

                                                        {receivingId === allocation.id && (
                                                            <div className="flex w-full max-w-xs flex-col gap-2">
                                                                <Input
                                                                    placeholder="Received amount"
                                                                    value={receiveAmount}
                                                                    onChange={(e) =>
                                                                        setReceiveAmount(e.target.value)
                                                                    }
                                                                />
                                                                <Input
                                                                    placeholder="Method (optional)"
                                                                    value={receiveMethod}
                                                                    onChange={(e) =>
                                                                        setReceiveMethod(e.target.value)
                                                                    }
                                                                />
                                                                <Input
                                                                    placeholder="Reference (optional)"
                                                                    value={receiveReference}
                                                                    onChange={(e) =>
                                                                        setReceiveReference(e.target.value)
                                                                    }
                                                                />
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() => receive(allocation.id)}
                                                                >
                                                                    Confirm receipt
                                                                </Button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationLinks paginator={allocations} />
                        </>
                    )}
                </DataPanel>
            </div>
        </AppShell>
    );
}
