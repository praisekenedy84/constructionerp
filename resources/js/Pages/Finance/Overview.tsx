import { formatCurrency, formatDate, formatPercent } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Building2,
    ClipboardList,
    HandCoins,
    Landmark,
    Receipt,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import StatusBadge from '@/Components/Shared/StatusBadge';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import AppShell from '@/Components/Layout/AppShell';
import SimpleBarChart from '@/Components/Charts/SimpleBarChart';
import SimplePieChart from '@/Components/Charts/SimplePieChart';
import { CHART_MUTED } from '@/lib/chart-colors';

interface PendingFund {
    id: number;
    requested_amount: string;
    requested_at: string | null;
    scope: 'project' | 'organization';
    project?: { id: number; code: string; name: string } | null;
    requester?: { id: number; name: string } | null;
}

interface AwaitingFulfillment {
    id: number;
    requisition_no: string;
    status: string;
    fulfillment_type: string;
    amount: string;
    updated_at: string | null;
    project?: { id: number; code: string; name: string } | null;
    requestor?: { id: number; name: string } | null;
}

interface FinanceOverviewProps extends PageProps {
    summary: {
        project_cash_on_hand: string;
        organization_cash_on_hand: string;
        committed: string;
        outstanding: string;
        disbursed: string;
        budget_utilization: number;
        pending_fund_count: number;
        pending_fund_amount: string;
        awaiting_fulfillment_count: number;
        direct_expenses_total: string;
        overhead_total: string;
    };
    fund_pipeline: {
        pending: number;
        approved: number;
        received: number;
        rejected: number;
    };
    org_use_breakdown: Array<{ bucket: string; label: string; amount: string }>;
    project_budget: Array<{
        name: string;
        spent: number;
        remaining: number;
        utilization: number;
    }>;
    pending_funds: Paginated<PendingFund>;
    awaiting_fulfillment: Paginated<AwaitingFulfillment>;
    active_projects: Paginated<{ id: number; code: string; name: string }>;
}

export default function FinanceOverview() {
    const {
        auth,
        summary,
        fund_pipeline,
        org_use_breakdown,
        project_budget,
        pending_funds,
        awaiting_fulfillment,
        active_projects,
    } = usePage<FinanceOverviewProps>().props;

    const canApprove = hasPermission(auth.user, 'budgets', 'approve');
    const canFulfill = hasPermission(auth.user, 'requisitions', 'fulfill');
    const pendingFundRows = pending_funds.data ?? [];
    const awaitingRows = awaiting_fulfillment.data ?? [];
    const projectRows = active_projects.data ?? [];

    const cards = [
        {
            label: 'Finance Wallet',
            value: formatCurrency(summary.project_cash_on_hand),
            sub: `${formatCurrency(summary.outstanding)} outstanding to pay`,
            icon: Wallet,
            href: '/finance/finance-transactions',
            color: 'text-green-700',
        },
        {
            label: 'Manager Accounts',
            value: formatCurrency(summary.organization_cash_on_hand),
            sub: 'Source funds & deposits',
            icon: Landmark,
            href: '/finance/accounts',
            color: 'text-blue-700',
        },
        {
            label: 'Pending Fund Requests',
            value: summary.pending_fund_count,
            sub: formatCurrency(summary.pending_fund_amount) + ' requested',
            icon: ClipboardList,
            href: '/finance/approvals?status=pending',
            color: 'text-amber-700',
        },
        {
            label: 'Awaiting Disbursement',
            value: summary.awaiting_fulfillment_count,
            sub: `${formatCurrency(summary.committed)} committed`,
            icon: HandCoins,
            href: canFulfill ? '/requisitions/fulfill-queue' : '/finance/approvals',
            color: 'text-slate-900',
        },
    ];

    const secondaryCards = [
        {
            label: 'Budget Utilization',
            value: formatPercent(summary.budget_utilization),
            sub: 'Across active projects',
            icon: TrendingUp,
            href: '/reports/preview/budget-utilization',
        },
        {
            label: 'Direct Expenses',
            value: formatCurrency(summary.direct_expenses_total),
            sub: 'Project-linked expenses',
            icon: Receipt,
            href: '/finance/expenses',
        },
        {
            label: 'Overhead',
            value: formatCurrency(summary.overhead_total),
            sub: 'Indirect / administrative spend',
            icon: Building2,
            href: '/finance/overhead',
        },
        {
            label: 'Disbursed',
            value: formatCurrency(summary.disbursed),
            sub: 'Cash paid out to date',
            icon: Banknote,
            href: '/finance/approvals',
        },
    ];

    const quickLinks = [
        { label: 'Fund Approvals', href: '/finance/approvals' },
        { label: 'Accounts', href: '/finance/accounts' },
        { label: 'Fund Approvals', href: '/finance/approvals' },
        { label: 'Finance Wallet', href: '/finance/finance-transactions' },
        { label: 'Expenses', href: '/finance/expenses' },
        { label: 'Overhead', href: '/finance/overhead' },
        ...(canFulfill
            ? [{ label: 'Fulfill Queue', href: '/requisitions/fulfill-queue' }]
            : []),
        { label: 'Reports', href: '/reports' },
    ];

    const pipelineChart = [
        { name: 'Pending', value: fund_pipeline.pending },
        { name: 'Approved', value: fund_pipeline.approved },
        { name: 'Received', value: fund_pipeline.received },
        { name: 'Rejected', value: fund_pipeline.rejected },
    ].filter((row) => row.value > 0);

    return (
        <AppShell title="Finance Overview">
            <Head title="Finance Overview" />
            <div className="space-y-8">
                <PageHeader
                    title="Finance Overview"
                    description={`Cash, funds, and spend at a glance${auth.user?.name ? ` — ${auth.user.name}` : ''}.`}
                />

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((card) => (
                        <Link key={card.label} href={card.href}>
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm text-slate-500">{card.label}</p>
                                    <card.icon className={`h-5 w-5 ${card.color}`} />
                                </div>
                                <p className={`mt-2 text-3xl font-bold ${card.color}`}>
                                    {card.value}
                                </p>
                                <p className="mt-1 text-xs text-slate-400">{card.sub}</p>
                            </div>
                        </Link>
                    ))}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {secondaryCards.map((card) => (
                        <Link key={card.label} href={card.href}>
                            <DataPanel title={card.label}>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-2xl font-bold text-slate-900">
                                            {card.value}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">{card.sub}</p>
                                    </div>
                                    <card.icon className="h-5 w-5 shrink-0 text-slate-400" />
                                </div>
                            </DataPanel>
                        </Link>
                    ))}
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel
                        title="Budget by Active Project"
                        description="Spent vs remaining across active projects"
                    >
                        {project_budget.length === 0 ? (
                            <p className="py-8 text-center text-sm text-slate-500">
                                No active project budget data yet.
                            </p>
                        ) : (
                            <SimpleBarChart
                                data={project_budget}
                                xKey="name"
                                series={[
                                    { key: 'spent', name: 'Spent', color: '#1d4ed8' },
                                    { key: 'remaining', name: 'Remaining', color: CHART_MUTED },
                                ]}
                                stacked
                            />
                        )}
                    </DataPanel>

                    <DataPanel
                        title="Fund Request Pipeline"
                        description="All project and administrative fund requests"
                    >
                        {pipelineChart.length === 0 ? (
                            <p className="py-8 text-center text-sm text-slate-500">
                                No fund requests yet.
                            </p>
                        ) : (
                            <SimplePieChart data={pipelineChart} valueLabel="Requests" />
                        )}
                    </DataPanel>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel
                        title="Administrative Fund Use"
                        description="How administrative cash has been spent"
                    >
                        {org_use_breakdown.length === 0 ? (
                            <p className="py-8 text-center text-sm text-slate-500">
                                No administrative fund uses recorded.
                            </p>
                        ) : (
                            <SimplePieChart
                                data={org_use_breakdown.map((row) => ({
                                    name: row.label,
                                    value: parseFloat(row.amount) || 0,
                                }))}
                                valueLabel="Amount"
                            />
                        )}
                    </DataPanel>

                    <DataPanel
                        title="Active Project Cashbooks"
                        description="Jump into a project finance view"
                    >
                        {projectRows.length === 0 ? (
                            <p className="py-8 text-center text-sm text-slate-500">
                                No active projects.
                            </p>
                        ) : (
                            <>
                                <ul className="divide-y divide-slate-100">
                                    {projectRows.map((project) => (
                                        <li key={project.id}>
                                            <Link
                                                href={`/finance/${project.id}`}
                                                className="flex items-center justify-between py-3 text-sm hover:text-blue-700"
                                            >
                                                <span>
                                                    <span className="font-mono text-xs text-slate-500">
                                                        {project.code}
                                                    </span>{' '}
                                                    <span className="font-medium text-slate-900">
                                                        {project.name}
                                                    </span>
                                                </span>
                                                <span className="text-xs text-slate-400">Open →</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                <PaginationLinks paginator={active_projects} pageName="projects_page" />
                            </>
                        )}
                    </DataPanel>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel
                        title={`Pending Fund Requests (${summary.pending_fund_count})`}
                        description="Waiting for approval or float"
                        actions={
                            <Link
                                href="/finance/approvals?status=pending"
                                className="text-sm font-medium text-blue-700 hover:underline"
                            >
                                View all
                            </Link>
                        }
                        noPadding
                    >
                        {pendingFundRows.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-slate-500">
                                No pending fund requests.
                            </p>
                        ) : (
                            <>
                                <ul className="divide-y divide-slate-100 px-6">
                                    {pendingFundRows.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex items-start justify-between gap-3 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-slate-900">
                                                    {row.scope === 'organization'
                                                        ? 'Administrative cash'
                                                        : `${row.project?.code ?? 'Project'} — ${row.project?.name ?? '—'}`}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {row.requester?.name ?? 'Unknown'} ·{' '}
                                                    {formatDate(row.requested_at)}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-semibold tabular-nums text-slate-900">
                                                    {formatCurrency(row.requested_amount)}
                                                </p>
                                                {canApprove && (
                                                    <Link
                                                        href="/finance/approvals?status=pending"
                                                        className="text-xs font-medium text-blue-700 hover:underline"
                                                    >
                                                        Review
                                                    </Link>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                <PaginationLinks paginator={pending_funds} pageName="funds_page" />
                            </>
                        )}
                    </DataPanel>

                    <DataPanel
                        title={`Awaiting Disbursement (${summary.awaiting_fulfillment_count})`}
                        description="Approved finance requisitions ready to pay"
                        actions={
                            canFulfill ? (
                                <Link
                                    href="/requisitions/fulfill-queue"
                                    className="text-sm font-medium text-blue-700 hover:underline"
                                >
                                    Fulfill queue
                                </Link>
                            ) : undefined
                        }
                        noPadding
                    >
                        {awaitingRows.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-slate-500">
                                Nothing waiting for cash disbursement.
                            </p>
                        ) : (
                            <>
                                <ul className="divide-y divide-slate-100 px-6">
                                    {awaitingRows.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex items-start justify-between gap-3 py-3"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/requisitions/${row.id}`}
                                                    className="font-mono text-sm font-medium text-slate-900 hover:text-blue-700"
                                                >
                                                    {row.requisition_no}
                                                </Link>
                                                <p className="text-xs text-slate-500">
                                                    {row.project?.code ?? '—'} ·{' '}
                                                    {row.requestor?.name ?? 'Unknown'} ·{' '}
                                                    {formatDate(row.updated_at)}
                                                </p>
                                                <div className="mt-1">
                                                    <StatusBadge status={row.status} />
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-semibold tabular-nums text-slate-900">
                                                    {formatCurrency(row.amount)}
                                                </p>
                                                <p className="text-xs capitalize text-slate-500">
                                                    {row.fulfillment_type.replace(/_/g, ' ')}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                <PaginationLinks
                                    paginator={awaiting_fulfillment}
                                    pageName="fulfill_page"
                                />
                            </>
                        )}
                    </DataPanel>
                </div>

                <DataPanel title="Finance Shortcuts">
                    <div className="flex flex-wrap gap-3">
                        {quickLinks.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            >
                                {link.label}
                            </Link>
                        ))}
                    </div>
                </DataPanel>
            </div>
        </AppShell>
    );
}
