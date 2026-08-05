import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface LifecycleEvent {
    type: string;
    label: string;
    at: string | null;
    amount: string | null;
    description?: string | null;
    payee?: string | null;
    reference_no?: string | null;
}

interface OrgAllocation {
    id: number;
    status: string;
    requested_amount: string;
    received_amount: string;
    utilized_amount: string;
    balance: string;
    method: string | null;
    reference_no: string | null;
    requested_at: string | null;
    received_at: string | null;
    decided_at: string | null;
    rejection_reason: string | null;
    requester?: { id: number; name: string } | null;
    approver?: { id: number; name: string } | null;
    lifecycle: LifecycleEvent[];
}

interface OrgUse {
    id: number;
    allocation_id: number;
    amount: string;
    method: string | null;
    payee: string | null;
    reference_no: string | null;
    disbursed_at: string | null;
    bucket: string;
    bucket_label: string;
    sub_type: string | null;
    description: string | null;
    disburser: string | null;
}

interface OrgCashProps extends PageProps {
    summary: {
        pending_count: number;
        pending_amount: string;
        received: string;
        utilized: string;
        cash_on_hand: string;
        disbursed: string;
    };
    use_breakdown: Array<{ bucket: string; label: string; amount: string }>;
    allocations: Paginated<OrgAllocation>;
    recent_uses: Paginated<OrgUse>;
}

export default function OrganizationCash() {
    const { summary, use_breakdown, allocations, recent_uses, auth } =
        usePage<OrgCashProps>().props;
    const canRequest = hasPermission(auth.user, 'budgets', 'create');
    const allocationRows = allocations.data ?? [];
    const useRows = recent_uses.data ?? [];
    const [expandedId, setExpandedId] = useState<number | null>(
        allocationRows.find((a) => a.status === 'received')?.id ?? allocationRows[0]?.id ?? null,
    );

    return (
        <AppShell title="Administrative Cash">
            <Head title="Administrative Cash" />
            <div className="space-y-6">
                <PageHeader
                    title="Administrative Cash"
                    description="Administrative funds approved for company use — overhead, payroll, office stock, and event inventory. Separate from project floats."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canRequest && (
                                <Link href="/finance/approvals">
                                    <Button>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Request / Approve Funds
                                    </Button>
                                </Link>
                            )}
                            <Link href="/finance/overhead">
                                <Button variant="outline">Record Overhead</Button>
                            </Link>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DataPanel title="Administrative Cash on Hand">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(summary.cash_on_hand)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">Available for administrative purposes</p>
                    </DataPanel>
                    <DataPanel title="Received (Floated)">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(summary.received)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">Approved administrative funds</p>
                    </DataPanel>
                    <DataPanel title="Utilized">
                        <p className="text-2xl font-bold text-slate-600">
                            {formatCurrency(summary.utilized)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            Spent on overhead, payroll & stock
                        </p>
                    </DataPanel>
                    <DataPanel title="Pending Requests">
                        <p className="text-2xl font-bold text-amber-700">
                            {formatCurrency(summary.pending_amount)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            {summary.pending_count} awaiting approval
                        </p>
                    </DataPanel>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <DataPanel title="Where Administrative Cash Was Used">
                        {use_breakdown.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                No administrative disbursements yet. Record overhead, post payroll, or
                                pay office/event stock from this wallet.
                            </p>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {use_breakdown.map((row) => (
                                    <li
                                        key={row.bucket}
                                        className="flex items-center justify-between py-3 text-sm"
                                    >
                                        <span className="text-slate-700">{row.label}</span>
                                        <span className="font-medium text-slate-900">
                                            {formatCurrency(row.amount)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </DataPanel>

                    <DataPanel title="Allowed Uses">
                        <ul className="space-y-2 text-sm text-slate-600">
                            <li>
                                <span className="font-medium text-slate-900">Overhead</span> —
                                rent, utilities, and other indirect company costs
                            </li>
                            <li>
                                <span className="font-medium text-slate-900">Payroll</span> —
                                salaries posted from payroll runs
                            </li>
                            <li>
                                <span className="font-medium text-slate-900">Office stock</span> —
                                office supplies purchased as overhead
                            </li>
                            <li>
                                <span className="font-medium text-slate-900">Event inventory</span> —
                                event organization materials purchased as overhead
                            </li>
                        </ul>
                        <p className="mt-4 text-xs text-slate-500">
                            Project direct expenses and project requisitions cannot draw from this
                            wallet.
                        </p>
                    </DataPanel>
                </div>

                <DataPanel title="Fund Lifecycle" noPadding>
                    {allocationRows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No administrative fund requests yet. Create one from Fund Approvals with
                            project set to Administrative.
                        </p>
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {allocationRows.map((allocation) => {
                                const open = expandedId === allocation.id;
                                return (
                                    <div key={allocation.id} className="px-6 py-4">
                                        <button
                                            type="button"
                                            className="flex w-full items-start justify-between gap-4 text-left"
                                            onClick={() =>
                                                setExpandedId(open ? null : allocation.id)
                                            }
                                        >
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-mono text-sm text-slate-900">
                                                        #{allocation.id}
                                                    </span>
                                                    <StatusBadge status={allocation.status} />
                                                    {allocation.reference_no && (
                                                        <span className="text-xs text-slate-500">
                                                            {allocation.reference_no}
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Requested by{' '}
                                                    {allocation.requester?.name ?? '—'}
                                                    {allocation.approver
                                                        ? ` · Approved by ${allocation.approver.name}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <div className="text-right text-sm">
                                                <p className="font-medium text-slate-900">
                                                    {formatCurrency(
                                                        allocation.status === 'received'
                                                            ? allocation.received_amount
                                                            : allocation.requested_amount,
                                                    )}
                                                </p>
                                                {allocation.status === 'received' && (
                                                    <p className="text-xs text-slate-500">
                                                        Balance{' '}
                                                        {formatCurrency(allocation.balance)}
                                                    </p>
                                                )}
                                            </div>
                                        </button>

                                        {open && (
                                            <ol className="relative mt-4 ml-2 space-y-4 border-l border-slate-200 pl-6">
                                                {allocation.lifecycle.map((event, index) => (
                                                    <li key={`${event.type}-${index}`}>
                                                        <span className="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border border-white bg-slate-300" />
                                                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                            <p className="text-sm font-medium text-slate-900">
                                                                {event.label}
                                                            </p>
                                                            {event.amount && (
                                                                <p className="text-sm text-slate-700">
                                                                    {formatCurrency(event.amount)}
                                                                </p>
                                                            )}
                                                        </div>
                                                        {event.at && (
                                                            <p className="text-xs text-slate-500">
                                                                {formatDate(event.at)}
                                                            </p>
                                                        )}
                                                        {(event.description ||
                                                            event.payee ||
                                                            event.reference_no) && (
                                                            <p className="mt-1 text-xs text-slate-500">
                                                                {[
                                                                    event.description,
                                                                    event.payee
                                                                        ? `Payee: ${event.payee}`
                                                                        : null,
                                                                    event.reference_no
                                                                        ? `Ref: ${event.reference_no}`
                                                                        : null,
                                                                ]
                                                                    .filter(Boolean)
                                                                    .join(' · ')}
                                                            </p>
                                                        )}
                                                    </li>
                                                ))}
                                                {allocation.rejection_reason && (
                                                    <li>
                                                        <p className="text-sm text-red-600">
                                                            {allocation.rejection_reason}
                                                        </p>
                                                    </li>
                                                )}
                                            </ol>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    <PaginationLinks paginator={allocations} pageName="allocations_page" />
                </DataPanel>

                <DataPanel title="Recent Uses" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Purpose</th>
                                <th className="px-6 py-3 font-medium">Detail</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 font-medium">Float</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {useRows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-6 py-10 text-center text-slate-500"
                                    >
                                        No uses recorded yet.
                                    </td>
                                </tr>
                            ) : (
                                useRows.map((use) => (
                                    <tr key={use.id}>
                                        <td className="px-6 py-3 text-slate-600">
                                            {use.disbursed_at
                                                ? formatDate(use.disbursed_at)
                                                : '—'}
                                        </td>
                                        <td className="px-6 py-3">
                                            <p className="font-medium text-slate-900">
                                                {use.bucket_label}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {use.sub_type ?? '—'}
                                            </p>
                                        </td>
                                        <td className="px-6 py-3 text-slate-600">
                                            {use.description ?? use.payee ?? '—'}
                                        </td>
                                        <td className="px-6 py-3 text-right font-medium">
                                            {formatCurrency(use.amount)}
                                        </td>
                                        <td className="px-6 py-3 font-mono text-slate-500">
                                            #{use.allocation_id}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={recent_uses} pageName="uses_page" />
                </DataPanel>
            </div>
        </AppShell>
    );
}
