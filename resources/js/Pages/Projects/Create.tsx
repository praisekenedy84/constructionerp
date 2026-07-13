import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const complianceRuleTypes = [
    { key: 'retention', label: 'Retention' },
    { key: 'advance_recovery', label: 'Advance Recovery' },
    { key: 'wht', label: 'Withholding Tax' },
    { key: 'defect_liability', label: 'Defect Liability' },
    { key: 'material_test', label: 'Material Test' },
    { key: 'hiv_report', label: 'HIV Report' },
];

export default function ProjectsCreate() {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        client: '',
        location: '',
        contract_amount: '',
        wht_percentage: '5',
        start_date: '',
        end_date: '',
        compliance_rules: complianceRuleTypes.map((r) => ({
            rule_type: r.key,
            rate: '',
            is_active: false,
            max_amount: '',
        })),
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/projects');
    }

    function updateRule(index: number, field: string, value: string | boolean) {
        const rules = [...data.compliance_rules];
        rules[index] = { ...rules[index], [field]: value };
        setData('compliance_rules', rules);
    }

    return (
        <AppShell title="New Project">
            <Head title="New Project" />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title="Create Project"
                    description="Net budget is computed from contract amount and WHT percentage."
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-4 text-sm font-semibold text-slate-900">Project Details</h3>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="code">Project Code</Label>
                                <Input
                                    id="code"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    required
                                />
                                {errors.code && <p className="text-sm text-red-600">{errors.code}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="name">Project Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="client">Client</Label>
                                <Input
                                    id="client"
                                    value={data.client}
                                    onChange={(e) => setData('client', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="location">Location</Label>
                                <Input
                                    id="location"
                                    value={data.location}
                                    onChange={(e) => setData('location', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="contract_amount">Contract Amount (TZS)</Label>
                                <Input
                                    id="contract_amount"
                                    type="number"
                                    step="0.01"
                                    value={data.contract_amount}
                                    onChange={(e) => setData('contract_amount', e.target.value)}
                                    required
                                />
                                {errors.contract_amount && (
                                    <p className="text-sm text-red-600">{errors.contract_amount}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="wht_percentage">WHT %</Label>
                                <Input
                                    id="wht_percentage"
                                    type="number"
                                    step="0.01"
                                    value={data.wht_percentage}
                                    onChange={(e) => setData('wht_percentage', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="start_date">Start Date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={data.start_date}
                                    onChange={(e) => setData('start_date', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="end_date">End Date</Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={data.end_date}
                                    onChange={(e) => setData('end_date', e.target.value)}
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-4 text-sm font-semibold text-slate-900">
                            Compliance Rules
                        </h3>
                        <div className="space-y-3">
                            {complianceRuleTypes.map((rule, index) => (
                                <div
                                    key={rule.key}
                                    className="flex flex-wrap items-center gap-4 rounded-lg border border-slate-100 p-3"
                                >
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={data.compliance_rules[index].is_active}
                                            onChange={(e) =>
                                                updateRule(index, 'is_active', e.target.checked)
                                            }
                                            className="rounded border-slate-300"
                                        />
                                        {rule.label}
                                    </label>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        placeholder="Rate %"
                                        className="w-24"
                                        value={data.compliance_rules[index].rate}
                                        onChange={(e) => updateRule(index, 'rate', e.target.value)}
                                    />
                                    <Input
                                        type="number"
                                        step="0.01"
                                        placeholder="Max amount"
                                        className="w-36"
                                        value={data.compliance_rules[index].max_amount}
                                        onChange={(e) =>
                                            updateRule(index, 'max_amount', e.target.value)
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link href="/projects">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating…' : 'Create Project'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
