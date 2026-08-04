import AppShell from '@/Components/Layout/AppShell';
import ComplianceItemsEditor, {
    AvailableComplianceRule,
    ComplianceItemForm,
    emptyComplianceItem,
} from '@/Components/Domain/ComplianceItemsEditor';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, Project, ProjectPhase } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ValuationsCreateProps extends PageProps {
    project: Project;
    next_certificate_no: number;
    other_ipcs_compliance_total: string;
    available_rules: AvailableComplianceRule[];
    phases: ProjectPhase[];
}

export default function ValuationsCreate() {
    const { project, next_certificate_no, other_ipcs_compliance_total, available_rules, phases } =
        usePage<ValuationsCreateProps>().props;
    const { data, setData, post, processing, errors } = useForm<{
        phase_id: string;
        compliance_items: ComplianceItemForm[];
    }>({
        phase_id: phases[0] ? String(phases[0].id) : '',
        compliance_items: [emptyComplianceItem()],
    });
    const selectedPhase = phases.find((phase) => String(phase.id) === data.phase_id) ?? null;

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.id}/valuations`);
    }

    return (
        <AppShell title="New IPC">
            <Head title={`New IPC-${next_certificate_no}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={`Create IPC-${next_certificate_no}`}
                    description={`${project.code} — select predefined compliance rules for this certificate.`}
                />

                <DataPanel title="Project">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-slate-500">Contract amount</dt>
                            <dd className="mt-1 font-semibold">
                                {formatCurrency(project.contract_amount)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Other IPCs compliance so far</dt>
                            <dd className="mt-1 font-semibold text-red-600">
                                −{formatCurrency(other_ipcs_compliance_total)}
                            </dd>
                        </div>
                    </dl>
                </DataPanel>

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Phase">
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700">Phase</label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                                value={data.phase_id}
                                onChange={(e) => setData('phase_id', e.target.value)}
                            >
                                <option value="">Select phase…</option>
                                {phases.map((phase) => (
                                    <option key={phase.id} value={phase.id}>
                                        Phase {phase.sequence_no}: {phase.name} (
                                        {formatCurrency(phase.disbursed_amount)})
                                    </option>
                                ))}
                            </select>
                            {selectedPhase && (
                                <p className="text-sm text-slate-600">
                                    Rate % is calculated from phase disbursed amount:{' '}
                                    <span className="font-semibold">
                                        {formatCurrency(selectedPhase.disbursed_amount)}
                                    </span>
                                </p>
                            )}
                            {phases.length === 0 && (
                                <p className="text-sm text-amber-700">
                                    Add a project phase with a disbursed amount before creating an IPC.
                                </p>
                            )}
                            {errors.phase_id && <p className="text-sm text-red-600">{errors.phase_id}</p>}
                        </div>
                    </DataPanel>
                    <DataPanel title={`IPC-${next_certificate_no} Compliance Rules`}>
                        <ComplianceItemsEditor
                            items={data.compliance_items}
                            availableRules={available_rules}
                            baseAmount={String(selectedPhase?.disbursed_amount ?? '0')}
                            baseLabel="Phase disbursed amount"
                            otherIpcsTotal={other_ipcs_compliance_total}
                            ipcLabel={`IPC-${next_certificate_no}`}
                            errors={errors}
                            onChange={(items) => setData('compliance_items', items)}
                        />
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link href={`/projects/${project.id}/valuations`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button
                            type="submit"
                            disabled={processing || available_rules.length === 0}
                        >
                            {processing ? 'Creating…' : `Save IPC-${next_certificate_no}`}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
