import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, Project } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ValuationsCreateProps extends PageProps {
    project: Project;
    preview_deductions?: { rule_type: string; rate: string; amount: string }[];
}

export default function ValuationsCreate() {
    const { project, preview_deductions } = usePage<ValuationsCreateProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        gross_value: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.id}/valuations`);
    }

    const totalDeductions = (preview_deductions ?? []).reduce(
        (sum, d) => sum + parseFloat(d.amount),
        0,
    );
    const gross = parseFloat(data.gross_value) || 0;

    return (
        <AppShell title="New Valuation">
            <Head title="New Valuation" />
            <div className="mx-auto max-w-2xl space-y-6">
                <PageHeader
                    title="Create Valuation"
                    description={`Deductions applied in fixed order per ${project.code} compliance rules.`}
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Gross Value">
                        <div className="space-y-2">
                            <Label>Gross Value (TZS)</Label>
                            <AmountInput
                                value={data.gross_value}
                                onValueChange={(v) => setData('gross_value', v)}
                                required
                            />
                            {errors.gross_value && (
                                <p className="text-sm text-red-600">{errors.gross_value}</p>
                            )}
                        </div>
                    </DataPanel>

                    {preview_deductions && preview_deductions.length > 0 && (
                        <DataPanel title="Deduction Preview">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Rule</th>
                                        <th className="pb-2 font-medium">Rate</th>
                                        <th className="pb-2 text-right font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {preview_deductions.map((d, i) => (
                                        <tr key={i}>
                                            <td className="py-2 capitalize">
                                                {d.rule_type.replace(/_/g, ' ')}
                                            </td>
                                            <td className="py-2">{d.rate}%</td>
                                            <td className="py-2 text-right text-red-600">
                                                {formatCurrency(d.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-200">
                                        <td colSpan={2} className="pt-3 font-medium">
                                            Net Value
                                        </td>
                                        <td className="pt-3 text-right font-bold">
                                            {formatCurrency(gross - totalDeductions)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </DataPanel>
                    )}

                    <div className="flex justify-end gap-3">
                        <Link href={`/projects/${project.id}/valuations`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating…' : 'Create Draft'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
