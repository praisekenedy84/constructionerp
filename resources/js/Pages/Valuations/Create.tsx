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
import { PageProps, Project } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ValuationsCreateProps extends PageProps {
    project: Project;
    next_certificate_no: number;
    other_ipcs_compliance_total: string;
    available_rules: AvailableComplianceRule[];
}

export default function ValuationsCreate() {
    const { project, next_certificate_no, other_ipcs_compliance_total, available_rules } =
        usePage<ValuationsCreateProps>().props;
    const { data, setData, post, processing, errors } = useForm<{
        compliance_items: ComplianceItemForm[];
    }>({
        compliance_items: [emptyComplianceItem()],
    });

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
                    <DataPanel title={`IPC-${next_certificate_no} Compliance Rules`}>
                        <ComplianceItemsEditor
                            items={data.compliance_items}
                            availableRules={available_rules}
                            contractAmount={String(project.contract_amount)}
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
