import AppShell from '@/Components/Layout/AppShell';
import SimpleBarChart from '@/Components/Charts/SimpleBarChart';
import DataPanel from '@/Components/Shared/DataPanel';
import ExportButton from '@/Components/Shared/ExportButton';
import PageHeader from '@/Components/Shared/PageHeader';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, Project, ReconciliationSummary } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface ReconciliationProps extends PageProps {
    project: Project;
    summary: ReconciliationSummary;
}

export default function Reconciliation() {
    const { project, summary } = usePage<ReconciliationProps>().props;

    const rows = [
        { label: 'Committed', value: summary.committed, color: 'text-slate-900' },
        { label: 'Disbursed', value: summary.disbursed, color: 'text-slate-600' },
        { label: 'Outstanding', value: summary.outstanding, color: 'text-amber-700' },
        { label: 'Cash on Hand', value: summary.cash_on_hand, color: 'text-green-700' },
    ];

    return (
        <AppShell title="Reconciliation">
            <Head title="Reconciliation" />
            <div className="space-y-6">
                <PageHeader
                    title="Cash Reconciliation"
                    description={`${project.code} — Outstanding is cash still owed on approved finance requests`}
                    actions={
                        <ExportButton
                            slug="cash-reconciliation"
                            filters={{ project_id: project.id }}
                        />
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {rows.map((row) => (
                        <DataPanel key={row.label} title={row.label}>
                            <p className={`text-2xl font-bold ${row.color}`}>
                                {formatCurrency(row.value)}
                            </p>
                        </DataPanel>
                    ))}
                </div>

                <DataPanel title="Reconciliation Breakdown">
                    <SimpleBarChart
                        data={rows.map((row) => ({
                            name: row.label,
                            amount: parseFloat(row.value) || 0,
                        }))}
                        xKey="name"
                        series={[{ key: 'amount', name: 'Amount (TZS)', color: '#1d4ed8' }]}
                    />
                </DataPanel>

                <DataPanel title="What each figure means">
                    <div className="space-y-3 text-sm text-slate-700">
                        <p>
                            <span className="font-semibold">Committed</span> — approved or
                            amended finance requests not yet fulfilled (cash still reserved).
                        </p>
                        <p>
                            <span className="font-semibold">Disbursed</span> — total cash
                            already paid out on this project (historical).
                        </p>
                        <p>
                            <span className="font-semibold">Outstanding</span> — remaining
                            amount still to pay from committed finance requests (
                            {formatCurrency(summary.outstanding)}).
                        </p>
                        <p>
                            <span className="font-semibold">Cash on Hand</span> — money
                            floated to finance that has not yet been utilized (
                            {formatCurrency(summary.cash_on_hand)}).
                        </p>
                    </div>
                </DataPanel>
            </div>
        </AppShell>
    );
}
