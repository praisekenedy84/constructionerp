import AppShell from '@/Components/Layout/AppShell';
import ComplianceItemsEditor, {
    AvailableComplianceRule,
    ComplianceItemForm,
} from '@/Components/Domain/ComplianceItemsEditor';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, Project, Valuation, ValuationDeduction } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ValuationsEditProps extends PageProps {
    project: Project;
    valuation: Valuation & { deductions: ValuationDeduction[] };
    other_ipcs_compliance_total: string;
    available_rules: AvailableComplianceRule[];
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
    const { project, valuation, other_ipcs_compliance_total, available_rules } =
        usePage<ValuationsEditProps>().props;
    const { data, setData, put, processing, errors } = useForm<{
        compliance_items: ComplianceItemForm[];
    }>({
        compliance_items: toFormItems(valuation.deductions ?? [], available_rules),
    });

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
                    <DataPanel title={`${ipcLabel} Compliance Rules`}>
                        <ComplianceItemsEditor
                            items={data.compliance_items}
                            availableRules={available_rules}
                            contractAmount={String(project.contract_amount)}
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
