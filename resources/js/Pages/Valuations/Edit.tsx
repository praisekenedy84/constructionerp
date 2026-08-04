import AppShell from '@/Components/Layout/AppShell';
import ComplianceItemsEditor, {
    AvailableComplianceRule,
    ComplianceItemForm,
} from '@/Components/Domain/ComplianceItemsEditor';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, Project, ProjectPhase, Valuation, ValuationDeduction } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ValuationsEditProps extends PageProps {
    project: Project;
    valuation: Valuation & { deductions: ValuationDeduction[] };
    other_ipcs_compliance_total: string;
    available_rules: AvailableComplianceRule[];
    phases: ProjectPhase[];
}

function toFormItems(
    deductions: ValuationDeduction[],
    availableRules: AvailableComplianceRule[],
): ComplianceItemForm[] {
    if (deductions.length === 0) {
        return [
            {
                compliance_rule_id: '',
                calculation_type: 'rate_percent',
                rate: '',
                fixed_amount: '',
            },
        ];
    }

    return deductions.map((d) => {
        const ruleStillAvailable =
            d.compliance_rule_id != null &&
            availableRules.some((r) => r.id === d.compliance_rule_id);

        return {
            compliance_rule_id: ruleStillAvailable ? String(d.compliance_rule_id) : '',
            calculation_type: d.calculation_type,
            rate: d.rate ?? '',
            fixed_amount: d.fixed_amount ?? '',
        };
    });
}

export default function ValuationsEdit() {
    const { project, valuation, other_ipcs_compliance_total, available_rules, phases } =
        usePage<ValuationsEditProps>().props;
    const { data, setData, put, processing, errors } = useForm<{
        phase_id: string;
        compliance_items: ComplianceItemForm[];
    }>({
        phase_id: String(valuation.phase_id),
        compliance_items: toFormItems(valuation.deductions ?? [], available_rules),
    });
    const selectedPhase = phases.find((phase) => String(phase.id) === data.phase_id) ?? null;

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/projects/${project.id}/valuations/${valuation.id}`);
    }

    const ipcLabel = `IPC-${valuation.certificate_no}`;

    return (
        <AppShell title={`Edit ${ipcLabel}`}>
            <Head title={`Edit ${ipcLabel}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={`Edit ${ipcLabel}`}
                    description={`${project.code} — update compliance rules for this certificate.`}
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
                            <dt className="text-slate-500">Other IPCs compliance</dt>
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
                            {errors.phase_id && <p className="text-sm text-red-600">{errors.phase_id}</p>}
                        </div>
                    </DataPanel>
                    <DataPanel title={`${ipcLabel} Compliance Rules`}>
                        <ComplianceItemsEditor
                            items={data.compliance_items}
                            availableRules={available_rules}
                            baseAmount={String(selectedPhase?.disbursed_amount ?? '0')}
                            baseLabel="Phase disbursed amount"
                            otherIpcsTotal={other_ipcs_compliance_total}
                            ipcLabel={ipcLabel}
                            errors={errors}
                            onChange={(items) => setData('compliance_items', items)}
                        />
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link href={`/projects/${project.id}/valuations/${valuation.id}`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button
                            type="submit"
                            disabled={processing || available_rules.length === 0}
                        >
                            {processing ? 'Saving…' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
