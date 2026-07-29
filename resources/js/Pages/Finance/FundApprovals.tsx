import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { CashAllocation, ListingFilters, PageProps, Paginated, Project } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface FundApprovalsProps extends PageProps {
    allocations: Paginated<CashAllocation>;
    projects: Pick<Project, 'id' | 'code' | 'name'>[];
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
    const { allocations, auth, filters, projects, summary } = usePage<FundApprovalsProps>().props;
    const rows = allocations.data ?? [];
    const [requestOpen, setRequestOpen] = useState(false);
    const [rejectingId, setRejectingId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [receivingId, setReceivingId] = useState<number | null>(null);
    const [receiveAmount, setReceiveAmount] = useState('');
    const [receiveMethod, setReceiveMethod] = useState('');
    const [receiveReference, setReceiveReference] = useState('');

    const canApprove = hasPermission(auth.user, 'budgets', 'approve');
    const canReject = hasPermission(auth.user, 'budgets', 'reject');
    const canReceive = hasPermission(auth.user, 'budgets', 'receive');
    const canRequest = hasPermission(auth.user, 'budgets', 'create');

    const [approvingId, setApprovingId] = useState<number | null>(null);
    const [approveAmount, setApproveAmount] = useState('');
    const [approveMethod, setApproveMethod] = useState('');
    const [approveReference, setApproveReference] = useState('');

    const {
        data: requestData,
        setData: setRequestData,
        post: postRequest,
        processing: requestProcessing,
        errors: requestErrors,
        reset: resetRequest,
        clearErrors: clearRequestErrors,
        isDirty: requestDirty,
    } = useForm({
        project_id: '',
        requested_amount: '',
        method: '',
        reference_no: '',
    });

    function openRequestDialog() {
        clearRequestErrors();
        setRequestOpen(true);
    }

    function closeRequestDialog() {
        if (!confirmDiscardIfDirty(requestDirty)) {
            return;
        }
        setRequestOpen(false);
        resetRequest();
        clearRequestErrors();
    }

    function submitRequest(e: FormEvent) {
        e.preventDefault();
        postRequest('/finance/cash-requests', {
            onSuccess: () => {
                resetRequest();
                setRequestOpen(false);
            },
        });
    }

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
        router.post(`/finance/cash-requests/${id}/approve`, {
            approved_amount: approveAmount || undefined,
            method: approveMethod || undefined,
            reference_no: approveReference || undefined,
        }, {
            onSuccess: () => {
                setApprovingId(null);
                setApproveAmount('');
                setApproveMethod('');
                setApproveReference('');
            },
        });
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
                    description="Finance requests funds; manager approval deducts project budget and floats cash on hand for disbursements."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canRequest && (
                                <Button onClick={openRequestDialog}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create New Request
                                </Button>
                            )}
                            <Button variant="outline" onClick={() => exportReport('xlsx')}>
                                <FileSpreadsheet className="mr-2 h-4 w-4" />
                                Export Excel
                            </Button>
                            <Button variant="outline" onClick={() => exportReport('pdf')}>
                                <Download className="mr-2 h-4 w-4" />
                                Export PDF
                            </Button>
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
                                                        {allocation.project?.code ?? 'ORG'}
                                                    </p>
                                                    <p className="text-xs">
                                                        {allocation.project?.name ??
                                                            'Organization (general)'}
                                                    </p>
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
                                                                    onClick={() => {
                                                                        setApprovingId(
                                                                            approvingId === allocation.id
                                                                                ? null
                                                                                : allocation.id,
                                                                        );
                                                                        setApproveAmount(
                                                                            allocation.requested_amount,
                                                                        );
                                                                        setRejectingId(null);
                                                                    }}
                                                                >
                                                                    Approve / Amend
                                                                </Button>
                                                                {canReject && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="border-red-300 text-red-700"
                                                                        onClick={() => {
                                                                            setRejectingId(
                                                                                rejectingId === allocation.id
                                                                                    ? null
                                                                                    : allocation.id,
                                                                            );
                                                                            setApprovingId(null);
                                                                        }}
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

                                                        {allocation.status === 'received' && (
                                                            <span className="text-xs text-green-700">
                                                                Floated to cash on hand
                                                            </span>
                                                        )}

                                                        {allocation.status === 'rejected' && (
                                                            <span className="text-xs text-slate-400">
                                                                Rejected
                                                            </span>
                                                        )}

                                                        {approvingId === allocation.id && (
                                                            <div className="flex w-full max-w-xs flex-col gap-2">
                                                                <p className="text-xs text-slate-500">
                                                                    Leave amount as-is or amend before funding cash
                                                                    on hand.
                                                                </p>
                                                                <AmountInput
                                                                    placeholder="Approved amount"
                                                                    value={approveAmount}
                                                                    onValueChange={setApproveAmount}
                                                                />
                                                                <Input
                                                                    placeholder="Method (optional)"
                                                                    value={approveMethod}
                                                                    onChange={(e) =>
                                                                        setApproveMethod(e.target.value)
                                                                    }
                                                                />
                                                                <Input
                                                                    placeholder="Reference (optional)"
                                                                    value={approveReference}
                                                                    onChange={(e) =>
                                                                        setApproveReference(e.target.value)
                                                                    }
                                                                />
                                                                <Button
                                                                    size="sm"
                                                                    className="bg-green-700 hover:bg-green-800"
                                                                    onClick={() => approve(allocation.id)}
                                                                >
                                                                    Confirm &amp; float cash
                                                                </Button>
                                                            </div>
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
                                                                <AmountInput
                                                                    placeholder="Received amount"
                                                                    value={receiveAmount}
                                                                    onValueChange={setReceiveAmount}
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

            {canRequest && (
                <Dialog
                    open={requestOpen}
                    onOpenChange={(open) => {
                        if (open) {
                            openRequestDialog();
                        } else {
                            closeRequestDialog();
                        }
                    }}
                    title="Create New Fund Request"
                    description="Select a project or Organization (general). A manager will review the request."
                >
                    <form onSubmit={submitRequest} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="request-project">Allocate funds to</Label>
                            <select
                                id="request-project"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                value={requestData.project_id}
                                onChange={(e) => setRequestData('project_id', e.target.value)}
                            >
                                <option value="">Organization (general)</option>
                                {(projects ?? []).map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} — {p.name}
                                    </option>
                                ))}
                            </select>
                            {requestErrors.project_id && (
                                <p className="text-sm text-red-600">{requestErrors.project_id}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="request-amount">Amount (TZS)</Label>
                            <AmountInput
                                id="request-amount"
                                value={requestData.requested_amount}
                                onValueChange={(v) => setRequestData('requested_amount', v)}
                                required
                            />
                            {requestErrors.requested_amount && (
                                <p className="text-sm text-red-600">{requestErrors.requested_amount}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="request-method">Method</Label>
                            <Input
                                id="request-method"
                                value={requestData.method}
                                onChange={(e) => setRequestData('method', e.target.value)}
                                placeholder="Bank transfer"
                            />
                            {requestErrors.method && (
                                <p className="text-sm text-red-600">{requestErrors.method}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="request-reference">Reference No</Label>
                            <Input
                                id="request-reference"
                                value={requestData.reference_no}
                                onChange={(e) => setRequestData('reference_no', e.target.value)}
                            />
                            {requestErrors.reference_no && (
                                <p className="text-sm text-red-600">{requestErrors.reference_no}</p>
                            )}
                        </div>
                        <DialogFormActions
                            onCancel={closeRequestDialog}
                            processing={requestProcessing}
                            submitLabel="Submit Request"
                            processingLabel="Submitting…"
                        />
                    </form>
                </Dialog>
            )}
        </AppShell>
    );
}
