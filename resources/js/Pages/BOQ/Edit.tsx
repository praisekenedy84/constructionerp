import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { BoqCategory, PageProps, Project } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface CategoryOption {
    value: BoqCategory;
    label: string;
}

interface BoqEditProps extends PageProps {
    project: Project;
    sectionNames: string[];
    categories: CategoryOption[];
    item: {
        id: number;
        section: string;
        description: string;
        unit: string;
        category: BoqCategory;
        budgeted_qty: string;
        unit_rate: string;
    };
}

export default function BoqEdit() {
    const { project, sectionNames, categories, item } = usePage<BoqEditProps>().props;
    const { data, setData, put, processing, errors } = useForm({
        section: item.section,
        description: item.description,
        unit: item.unit,
        category: item.category,
        budgeted_qty: item.budgeted_qty,
        unit_rate: item.unit_rate,
    });

    const lineTotal =
        (parseFloat(data.budgeted_qty) || 0) * (parseFloat(data.unit_rate) || 0);

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/projects/${project.id}/boq/items/${item.id}`);
    }

    return (
        <AppShell title="Edit BOQ Item">
            <Head title={`Edit BOQ — ${project.name}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title="Edit BOQ Item"
                    description={`${project.code} — ${project.name}`}
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Item details">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="section">Section</Label>
                                <Input
                                    id="section"
                                    list="boq-sections"
                                    value={data.section}
                                    onChange={(e) => setData('section', e.target.value)}
                                    required
                                />
                                <datalist id="boq-sections">
                                    {sectionNames.map((name) => (
                                        <option key={name} value={name} />
                                    ))}
                                </datalist>
                                {errors.section && (
                                    <p className="text-sm text-red-600">{errors.section}</p>
                                )}
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    required
                                />
                                {errors.description && (
                                    <p className="text-sm text-red-600">{errors.description}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="unit">Unit</Label>
                                <Input
                                    id="unit"
                                    value={data.unit}
                                    onChange={(e) => setData('unit', e.target.value)}
                                    required
                                />
                                {errors.unit && (
                                    <p className="text-sm text-red-600">{errors.unit}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="category">Category</Label>
                                <select
                                    id="category"
                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                                    value={data.category}
                                    onChange={(e) =>
                                        setData('category', e.target.value as BoqCategory)
                                    }
                                >
                                    {categories.map((category) => (
                                        <option key={category.value} value={category.value}>
                                            {category.label}
                                        </option>
                                    ))}
                                </select>
                                {errors.category && (
                                    <p className="text-sm text-red-600">{errors.category}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="budgeted_qty">Qty</Label>
                                <AmountInput
                                    id="budgeted_qty"
                                    value={data.budgeted_qty}
                                    onValueChange={(v) => setData('budgeted_qty', v)}
                                    maxDecimals={3}
                                    required
                                />
                                {errors.budgeted_qty && (
                                    <p className="text-sm text-red-600">{errors.budgeted_qty}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="unit_rate">Rate</Label>
                                <AmountInput
                                    id="unit_rate"
                                    value={data.unit_rate}
                                    onValueChange={(v) => setData('unit_rate', v)}
                                    required
                                />
                                {errors.unit_rate && (
                                    <p className="text-sm text-red-600">{errors.unit_rate}</p>
                                )}
                            </div>
                        </div>
                        <p className="mt-4 text-sm text-slate-600">
                            Line amount:{' '}
                            <span className="font-medium text-slate-900">
                                {formatCurrency(lineTotal || null)}
                            </span>
                        </p>
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link href={`/projects/${project.id}/boq`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
