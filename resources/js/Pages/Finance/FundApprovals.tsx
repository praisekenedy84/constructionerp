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
import { PaymentMethodSelect } from '@/Components/ui/payment-method-select';
import { formatCurrency } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import {
    CashAllocation,
    ListingFilters,
    MoneyAccount,
    PageProps,
    Paginated,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface FundApprovalsProps extends PageProps {
    allocations: Paginated<CashAllocation>;
    manager_accounts: MoneyAccount[];
    finance_balance: string;
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
    const { allocations, auth, filters, manager_accounts, finance_balance, summary } =
        usePage<FundApprovalsProps>().props;
    const rows = allocations.data ?? [];
    const [requestOpen, setRequestOpen] = useState(false);
    const [rejectingId, setRejectingId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [approvingId, setApprovingId] = useState<number | null>(null);
    const [approveAmount, setApproveAmount] = useState('');
    const [approveMethod, setApproveMethod] = useState('');
    const [approveReference, setApproveReference] = useState('');
    const [sourceAccountId, setSourceAccountId] = useState('');

    const canApprove = hasPermission(auth.user, 'budgets', 'approve');
    const canReject = hasPermission(auth.user, 'budgets', 'reject');
    const canRequest = hasPermission(auth.user, 'budgets', 'create');

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
        requested_amount: '',
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
        router.post(
            `/finance/cash-requests/${id}/approve`,
            {
                source_account_id: sourceAccountId || undefined,
                approved_amount: approveAmount || undefined,
                method: approveMethod || undefined,
                reference_no: approveReference || undefined,
            },
            {
                onSuccess: () => {
                    setApprovingId(null);
                    setApproveAmount('');
                    setApproveMethod('');
                    setApproveReference('');
                    setSourceAccountId('');
                },
            },
        );
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

    return (
        <AppShell title="Fund Approvals">
            <Head title="Fund Approvals" />
            <div className="space-y-6">
                <PageHeader
                    title="Fund Approvals"
                    description="Finance requests cash into the shared Finance Wallet. Manager approval transfers funds from a manager account — spendable on project or company expenses."
                    actions={
                        <>
                            <div className="flex items-center gap-3 text-sm">
                                <Link
                                    href="/finance/accounts"
                                    className="font-medium text-blue-700 hover:underline"
                                >
                                    Accounts
                                </Link>
                                <Link
                                    href="/finance/finance-transactions"
                                    className="font-medium text-blue-700 hover:underline"
                                >
                                    Finance Wallet
                                </Link>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => exportReport('xlsx')}
                                >
                                    <FileSpreadsheet className="h-4 w-4" />
                                    Excel
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => exportReport('pdf')}
                                >
                                    <Download className="h-4 w-4" />
                                    PDF
                                </Button>
                                {canRequest && (
                                    <Button onClick={openRequestDialog}>
                                        <Plus className="h-4 w-4" />
                                        Request Funds
                                    </Button>
                                )}
                            </div>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    {[
                        { label: 'Finance Wallet', value: formatCurrency(finance_balance), tone: 'text-slate-900' },
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
                    searchPlaceholder="Search reference, requester…"
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
                                <table className="w-full min-w-[1000px] text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                            <th className="px-4 py-3 font-medium">ID</th>
                                            <th className="px-4 py-3 font-medium">Requester</th>
                                            <th className="px-4 py-3 font-medium">Status</th>
                                            <th className="px-4 py-3 text-right font-medium">Requested</th>
                                            <th className="px-4 py-3 text-right font-medium">Received</th>
                                            <th className="px-4 py-3 font-medium">Source account</th>
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
                                                <td className="px-4 py-4 text-slate-600">
                                                    {allocation.source_account?.name ?? '—'}
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
                                                                        setSourceAccountId(
                                                                            manager_accounts[0]
                                                                                ? String(manager_accounts[0].id)
                                                                                : '',
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

                                                        {allocation.status === 'received' && (
                                                            <span className="text-xs text-green-700">
                                                                In Finance Wallet
                                                            </span>
                                                        )}

                                                        {approvingId === allocation.id && (
                                                            <div className="flex w-full max-w-xs flex-col gap-2">
                                                                <p className="text-xs text-slate-500">
                                                                    Choose the manager account to transfer from.
                                                                </p>
                                                                <select
                                                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                                                                    value={sourceAccountId}
                                                                    onChange={(e) =>
                                                                        setSourceAccountId(e.target.value)
                                                                    }
                                                                >
                                                                    <option value="">Select account…</option>
                                                                    {manager_accounts.map((account) => (
                                                                        <option key={account.id} value={account.id}>
                                                                            {account.name} (
                                                                            {formatCurrency(account.balance)})
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                <AmountInput
                                                                    placeholder="Approved amount"
                                                                    value={approveAmount}
                                                                    onValueChange={setApproveAmount}
                                                                />
                                                                <PaymentMethodSelect
                                                                    value={approveMethod}
                                                                    onChange={(e) =>
                                                                        setApproveMethod(e.target.value)
                                                                    }
                                                                    optional
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
                                                                    disabled={!sourceAccountId}
                                                                >
                                                                    Confirm &amp; transfer
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
                    title="Request Funds"
                    description="Request funds into the Finance Wallet. A manager will approve and transfer from one of their accounts."
                >
                    <form onSubmit={submitRequest} className="space-y-4">
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
