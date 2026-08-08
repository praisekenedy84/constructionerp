import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Project, ProjectPhase, Sale, Valuation, ValuationDeduction } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface PhaseShowProps extends PageProps {
    project: Project;
    phase: ProjectPhase & {
        retention_released_at?: string | null;
        retention_forfeited_at?: string | null;
    };
    sale?: Sale | null;
    valuations: Array<
        Valuation & {
            deductions: ValuationDeduction[];
        }
    >;
    summary: {
        contract_amount: string;
        phase_compliance_total: string;
        project_net_budget: string;
    };
}

export default function PhaseShow() {
    const { project, phase, sale = null, valuations, summary, auth } =
        usePage<PhaseShowProps>().props;
    const canUpdateProject = hasPermission(auth.user, 'projects', 'update');
    const canCreateValuation = hasPermission(auth.user, 'valuations', 'create');
    const canReadSales = hasPermission(auth.user, 'sales', 'read');
    const phaseLabel = `Phase ${phase.sequence_no}: ${phase.name}`;
    const hasHeldRetention = Number(phase.retention_held_amount) > 0;
    const isClosed = phase.status === 'closed';

    function releaseRetention() {
        if (!confirm(`Release held retention for ${phaseLabel} into the project budget?`)) {
            return;
        }
        router.post(`/projects/${project.id}/phases/${phase.id}/retention/release`);
    }

    function forfeitRetention() {
        if (
            !confirm(
                `Forfeit held retention for ${phaseLabel}? It will stay excluded from the project budget.`,
            )
        ) {
            return;
        }
        router.post(`/projects/${project.id}/phases/${phase.id}/retention/forfeit`);
    }

    function closePhase() {
        if (
            !confirm(
                `Close ${phaseLabel}? After closing, its share of project profit can be converted to a receivable.`,
            )
        ) {
            return;
        }
        router.post(`/projects/${project.id}/phases/${phase.id}/close`);
    }

    return (
        <AppShell title={phaseLabel}>
            <Head title={phaseLabel} />
            <div className="mx-auto max-w-4xl space-y-6">
                <PageHeader
                    title={phaseLabel}
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Link href={`/projects/${project.id}`}>
                                <Button variant="outline">Back to project</Button>
                            </Link>
                            {canCreateValuation && (
                                <Link
                                    href={`/projects/${project.id}/valuations/create?phase_id=${phase.id}`}
                                >
                                    <Button>Add IPC</Button>
                                </Link>
                            )}
                            {canReadSales && sale && (
                                <Link href={`/sales/${sale.id}`}>
                                    <Button variant="outline">View sale</Button>
                                </Link>
                            )}
                            {canUpdateProject && !isClosed && (
                                <Button variant="outline" onClick={closePhase}>
                                    Close phase
                                </Button>
                            )}
                            {canUpdateProject && hasHeldRetention && (
                                <>
                                    <Button variant="outline" onClick={releaseRetention}>
                                        Release retention
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="text-red-700 hover:bg-red-50"
                                        onClick={forfeitRetention}
                                    >
                                        Forfeit retention
                                    </Button>
                                </>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Disbursed
                        </p>
                        <p className="mt-1 text-lg font-semibold">
                            {formatCurrency(phase.disbursed_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Held retention
                        </p>
                        <p className="mt-1 text-lg font-semibold text-amber-700">
                            {formatCurrency(phase.retention_held_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Released
                        </p>
                        <p className="mt-1 text-lg font-semibold text-green-700">
                            {formatCurrency(phase.retention_released_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Forfeited
                        </p>
                        <p className="mt-1 text-lg font-semibold text-red-700">
                            {formatCurrency(phase.retention_forfeited_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Other deductions
                        </p>
                        <p className="mt-1 text-lg font-semibold text-red-600">
                            −{formatCurrency(phase.other_deductions_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Phase net budget
                        </p>
                        <p className="mt-1 text-lg font-semibold">
                            {formatCurrency(phase.phase_net_budget)}
                        </p>
                    </div>
                </div>

                <DataPanel title="Phase details">
                    <dl className="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-slate-500">Status</dt>
                            <dd className="mt-1">
                                <StatusBadge status={String(phase.status)} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Retention status</dt>
                            <dd className="mt-1">
                                <StatusBadge status={String(phase.retention_status)} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Contract amount</dt>
                            <dd className="mt-1 font-medium">
                                {formatCurrency(summary.contract_amount)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Project net budget</dt>
                            <dd className="mt-1 font-medium">
                                {formatCurrency(summary.project_net_budget)}
                            </dd>
                        </div>
                        {phase.retention_released_at && (
                            <div>
                                <dt className="text-slate-500">Retention released</dt>
                                <dd className="mt-1 font-medium">
                                    {formatDate(phase.retention_released_at)}
                                </dd>
                            </div>
                        )}
                        {phase.retention_forfeited_at && (
                            <div>
                                <dt className="text-slate-500">Retention forfeited</dt>
                                <dd className="mt-1 font-medium">
                                    {formatDate(phase.retention_forfeited_at)}
                                </dd>
                            </div>
                        )}
                    </dl>
                    <p className="mt-4 text-sm text-slate-600">
                        Phase net budget = disbursed − held retention − other IPC deductions.
                        Released retention returns to the phase budget; forfeited retention does
                        not.
                    </p>
                </DataPanel>

                <DataPanel title="IPC breakdown" noPadding>
                    <div className="border-b border-slate-200 px-4 py-3 text-sm text-slate-600">
                        Total compliance on this phase:{' '}
                        <span className="font-medium text-red-700">
                            −{formatCurrency(summary.phase_compliance_total)}
                        </span>
                    </div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">IPC</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Created</th>
                                <th className="px-6 py-3 text-right font-medium">Compliance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {valuations.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-6 py-10 text-center text-slate-500"
                                    >
                                        No IPCs on this phase yet.
                                    </td>
                                </tr>
                            ) : (
                                valuations.map((valuation) => (
                                    <tr key={valuation.id}>
                                        <td className="px-6 py-3">
                                            <Link
                                                href={`/projects/${project.id}/valuations/${valuation.id}`}
                                                className="font-medium text-blue-700 hover:underline"
                                            >
                                                IPC-{valuation.certificate_no}
                                            </Link>
                                            {valuation.deductions?.length > 0 && (
                                                <ul className="mt-1 space-y-0.5 text-xs text-slate-500">
                                                    {valuation.deductions.map((d) => (
                                                        <li key={d.id}>
                                                            {d.name}: −{formatCurrency(d.amount)}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="px-6 py-3">
                                            <StatusBadge status={String(valuation.status)} />
                                        </td>
                                        <td className="px-6 py-3 text-slate-600">
                                            {formatDate(valuation.created_at)}
                                        </td>
                                        <td className="px-6 py-3 text-right font-medium text-red-700">
                                            −{formatCurrency(valuation.total_deductions)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                        {valuations.length > 0 && (
                            <tfoot>
                                <tr className="border-t border-slate-200 bg-slate-50">
                                    <td colSpan={3} className="px-6 py-3 font-semibold">
                                        Phase compliance total
                                    </td>
                                    <td className="px-6 py-3 text-right font-semibold text-red-700">
                                        −{formatCurrency(summary.phase_compliance_total)}
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
