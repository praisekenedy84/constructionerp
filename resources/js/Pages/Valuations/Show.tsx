import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Project, ProjectPhase, Valuation, ValuationDeduction } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface ValuationsShowProps extends PageProps {
    project: Project;
    valuation: Valuation & {
        deductions: ValuationDeduction[];
        creator?: { name: string };
        certifier?: { name: string } | null;
    };
    summary: {
        contract_amount: string;
        total_compliance: string;
        net_project_amount: string;
    };
    phases?: ProjectPhase[];
}

export default function ValuationsShow() {
    const { project, valuation, summary, auth, phases = [] } = usePage<ValuationsShowProps>().props;
    const phase = phases.find((entry) => entry.id === valuation.phase_id);
    const deductions = valuation.deductions ?? [];
    const isDraft = valuation.status === 'draft';
    const canUpdate = hasPermission(auth.user, 'valuations', 'update');
    const canDelete = hasPermission(auth.user, 'valuations', 'delete-soft');
    const canApprove = hasPermission(auth.user, 'valuations', 'approve');
    const ipcLabel = `IPC-${valuation.certificate_no}`;

    function certify() {
        if (!confirm(`Certify ${ipcLabel}? This cannot be undone.`)) {
            return;
        }
        router.post(`/valuations/${valuation.id}/certify`);
    }

    function destroy() {
        if (
            !confirm(
                `Archive ${ipcLabel}? Its compliance will be removed from the project net budget.`,
            )
        ) {
            return;
        }
        router.delete(`/projects/${project.id}/valuations/${valuation.id}`);
    }

    return (
        <AppShell title={ipcLabel}>
            <Head title={ipcLabel} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={ipcLabel}
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <div className="flex gap-2">
                            <Link href={`/projects/${project.id}/valuations`}>
                                <Button variant="outline">Back to IPCs</Button>
                            </Link>
                            {isDraft && canUpdate && (
                                <Link
                                    href={`/projects/${project.id}/valuations/${valuation.id}/edit`}
                                >
                                    <Button variant="outline">Edit</Button>
                                </Link>
                            )}
                            {isDraft && canDelete && (
                                <Button
                                    variant="outline"
                                    className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                    onClick={destroy}
                                >
                                    Delete
                                </Button>
                            )}
                            {isDraft && canApprove && (
                                <Button onClick={certify}>Certify</Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Contract amount
                        </p>
                        <p className="mt-1 text-lg font-semibold">
                            {formatCurrency(summary.contract_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Total {ipcLabel}
                        </p>
                        <p className="mt-1 text-lg font-semibold text-red-600">
                            −{formatCurrency(valuation.total_deductions)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Net project amount
                        </p>
                        <p className="mt-1 text-lg font-semibold">
                            {formatCurrency(summary.net_project_amount)}
                        </p>
                    </div>
                </div>

                <DataPanel title="Certificate">
                    <dl className="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-slate-500">Phase</dt>
                            <dd className="mt-1 font-medium">
                                {phase ? `Phase ${phase.sequence_no}: ${phase.name}` : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Status</dt>
                            <dd className="mt-1">
                                <StatusBadge status={String(valuation.status)} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Created</dt>
                            <dd className="mt-1 font-medium">
                                {formatDate(valuation.created_at)}
                                {valuation.creator?.name ? ` by ${valuation.creator.name}` : ''}
                            </dd>
                        </div>
                        {valuation.certified_at && (
                            <div>
                                <dt className="text-slate-500">Certified</dt>
                                <dd className="mt-1 font-medium">
                                    {formatDate(valuation.certified_at)}
                                    {valuation.certifier?.name
                                        ? ` by ${valuation.certifier.name}`
                                        : ''}
                                </dd>
                            </div>
                        )}
                    </dl>
                </DataPanel>

                <DataPanel title={`${ipcLabel} Compliance Rules`} noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Rule</th>
                                <th className="px-6 py-3 font-medium">Basis</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {deductions.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="px-6 py-10 text-center text-slate-500"
                                    >
                                        No compliance rules on this IPC.
                                    </td>
                                </tr>
                            ) : (
                                deductions.map((d) => (
                                    <tr key={d.id}>
                                        <td className="px-6 py-3 font-medium">{d.name}</td>
                                        <td className="px-6 py-3 text-slate-600">
                                            {d.calculation_type === 'fixed_amount'
                                                ? `Fixed ${formatCurrency(d.fixed_amount)}`
                                                : `${d.rate}% of phase disbursed amount`}
                                        </td>
                                        <td className="px-6 py-3 text-right text-red-600">
                                            −{formatCurrency(d.amount)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                        {deductions.length > 0 && (
                            <tfoot>
                                <tr className="border-t border-slate-200 bg-slate-50">
                                    <td colSpan={2} className="px-6 py-3 font-semibold">
                                        Total {ipcLabel}
                                    </td>
                                    <td className="px-6 py-3 text-right font-semibold text-red-700">
                                        −{formatCurrency(valuation.total_deductions)}
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </DataPanel>
            </div>
        </AppShell>
    );
}
