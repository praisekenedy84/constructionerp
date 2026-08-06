import AppShell from '@/Components/Layout/AppShell';
import SimpleBarChart from '@/Components/Charts/SimpleBarChart';
import SimplePieChart from '@/Components/Charts/SimplePieChart';
import DataPanel from '@/Components/Shared/DataPanel';
import { CHART_MUTED } from '@/lib/chart-colors';
import { formatCurrency, formatPercent } from '@/lib/formatters';
import { DashboardCharts, DashboardStats, PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Building2,
    ClipboardList,
    Landmark,
    TrendingUp,
    Wallet,
} from 'lucide-react';

interface DashboardPageProps extends PageProps {
    stats: DashboardStats;
    charts: DashboardCharts;
}

export default function Dashboard() {
    const { auth, stats, charts } = usePage<DashboardPageProps>().props;
    const financeWallet = stats.finance_wallet_balance ?? stats.cash_on_hand;
    const companyAccounts = stats.company_accounts_balance ?? '0';

    const cards = [
        {
            label: 'Active Projects',
            value: stats.active_projects,
            sub: `${stats.total_projects} total`,
            icon: Building2,
            href: '/projects',
            color: 'text-blue-700',
        },
        {
            label: 'Pending Approvals',
            value: stats.pending_approvals,
            sub: 'Awaiting review',
            icon: ClipboardList,
            href: '/requisitions/review-queue',
            color: 'text-amber-700',
        },
        {
            label: 'Budget Utilization',
            value: formatPercent(stats.budget_utilization),
            sub: 'Across active projects',
            icon: TrendingUp,
            href: '/reports/preview/budget-utilization',
            color: 'text-green-700',
        },
        {
            label: 'Company Accounts',
            value: formatCurrency(companyAccounts),
            sub: 'Total company bank & cash accounts',
            icon: Landmark,
            href: '/finance/accounts',
            color: 'text-slate-900',
        },
        {
            label: 'Finance Wallet',
            value: formatCurrency(financeWallet),
            sub: `${stats.open_requisitions} open requisitions · accountant balance`,
            icon: Wallet,
            href: '/finance/finance-transactions',
            color: 'text-indigo-700',
        },
    ];

    const quickLinks = [
        { label: 'New Requisition', href: '/requisitions/create' },
        { label: 'Review Queue', href: '/requisitions/review-queue' },
        { label: 'Fund Approvals', href: '/finance/approvals' },
        { label: 'Reports', href: '/reports' },
    ];

    return (
        <AppShell title="Dashboard">
            <Head title="Dashboard" />
            <div className="space-y-8">
                <div>
                    <h2 className="text-2xl font-bold text-slate-900">
                        Welcome back, {auth.user?.name}
                    </h2>
                    <p className="mt-1 text-slate-500">
                        Executive overview of projects, budget, cash, and approvals.
                    </p>
                </div>

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    {cards.map((card) => {
                        const valueText = String(card.value);
                        const isLongValue = valueText.length > 8;

                        return (
                            <Link key={card.label} href={card.href} className="min-w-0">
                                <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-sm text-slate-500">{card.label}</p>
                                        <card.icon className={`h-5 w-5 shrink-0 ${card.color}`} />
                                    </div>
                                    <p
                                        className={`mt-2 font-bold tabular-nums tracking-tight ${
                                            isLongValue ? 'text-xl leading-snug' : 'text-3xl'
                                        } ${card.color}`}
                                        title={valueText}
                                    >
                                        {card.value}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-400">{card.sub}</p>
                                </div>
                            </Link>
                        );
                    })}
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel
                        title="Budget by Active Project"
                        description="Spent vs remaining budget across active projects"
                    >
                        <SimpleBarChart
                            data={charts.project_budget}
                            xKey="name"
                            series={[
                                { key: 'spent', name: 'Spent', color: '#1d4ed8' },
                                { key: 'remaining', name: 'Remaining', color: CHART_MUTED },
                            ]}
                            stacked
                        />
                    </DataPanel>

                    <DataPanel
                        title="Requisition Pipeline"
                        description="Open requisitions grouped by status"
                    >
                        <SimplePieChart
                            data={charts.requisition_status.map((row) => ({
                                name: row.name,
                                value: row.count,
                            }))}
                            valueLabel="Requisitions"
                        />
                    </DataPanel>
                </div>

                <DataPanel title="Quick Actions">
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
