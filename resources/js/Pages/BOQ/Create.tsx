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

interface BoqCreateProps extends PageProps {
    project: Project;
    sectionNames: string[];
    categories: CategoryOption[];
}

interface LineItemForm {
    section: string;
    description: string;
    unit: string;
    category: BoqCategory;
    budgeted_qty: string;
    unit_rate: string;
}

const emptyLine = (section = ''): LineItemForm => ({
    section,
    description: '',
    unit: '',
    category: 'materials',
    budgeted_qty: '',
    unit_rate: '',
});

export default function BoqCreate() {
    const { project, sectionNames, categories } = usePage<BoqCreateProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        items: [emptyLine(sectionNames[0] ?? '')],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.id}/boq/items`);
    }

    function addLine() {
        const lastSection = data.items[data.items.length - 1]?.section ?? sectionNames[0] ?? '';
        setData('items', [...data.items, emptyLine(lastSection)]);
    }

    function removeLine(index: number) {
        if (data.items.length === 1) {
            return;
        }
        setData(
            'items',
            data.items.filter((_, i) => i !== index),
        );
    }

    function updateLine(index: number, field: keyof LineItemForm, value: string) {
        const items = [...data.items];
        items[index] = { ...items[index], [field]: value };
        setData('items', items);
    }

    const lineTotal = data.items.reduce(
        (sum, item) =>
            sum + (parseFloat(item.budgeted_qty) || 0) * (parseFloat(item.unit_rate) || 0),
        0,
    );

    return (
        <AppShell title="Add BOQ Items">
            <Head title={`Add BOQ — ${project.name}`} />
            <div className="mx-auto max-w-4xl space-y-6">
                <PageHeader
                    title="Add BOQ Items"
                    description={`Enter line items for ${project.code} — ${project.name}. You can also import from a file.`}
                    actions={
                        <Link href={`/projects/${project.id}/boq/import`}>
                            <Button type="button" variant="outline">
                                Import from file
                            </Button>
                        </Link>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel
                        title="BOQ Line Items"
                        description="Group items by section. New section names are created automatically."
                        actions={
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                Add Line
                            </Button>
                        }
                    >
                        <div className="space-y-4">
                            {data.items.map((item, index) => (
                                <div
                                    key={index}
                                    className="space-y-3 rounded-md border border-slate-200 p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-sm font-medium text-slate-700">
                                            Line {index + 1}
                                        </p>
                                        {data.items.length > 1 && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => removeLine(index)}
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Section</Label>
                                            <Input
                                                list={`boq-sections-${index}`}
                                                placeholder="e.g. Earthworks"
                                                value={item.section}
                                                onChange={(e) =>
                                                    updateLine(index, 'section', e.target.value)
                                                }
                                                required
                                            />
                                            <datalist id={`boq-sections-${index}`}>
                                                {sectionNames.map((name) => (
                                                    <option key={name} value={name} />
                                                ))}
                                            </datalist>
                                            {errors[`items.${index}.section`] && (
                                                <p className="text-sm text-red-600">
                                                    {errors[`items.${index}.section`]}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Category</Label>
                                            <select
                                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                value={item.category}
                                                onChange={(e) =>
                                                    updateLine(index, 'category', e.target.value)
                                                }
                                                required
                                            >
                                                {categories.map((category) => (
                                                    <option
                                                        key={category.value}
                                                        value={category.value}
                                                    >
                                                        {category.label}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors[`items.${index}.category`] && (
                                                <p className="text-sm text-red-600">
                                                    {errors[`items.${index}.category`]}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-6">
                                        <div className="space-y-2 sm:col-span-3">
                                            <Label>Description</Label>
                                            <Input
                                                placeholder="Item description"
                                                value={item.description}
                                                onChange={(e) =>
                                                    updateLine(index, 'description', e.target.value)
                                                }
                                                required
                                            />
                                            {errors[`items.${index}.description`] && (
                                                <p className="text-sm text-red-600">
                                                    {errors[`items.${index}.description`]}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Unit</Label>
                                            <Input
                                                placeholder="m3"
                                                value={item.unit}
                                                onChange={(e) =>
                                                    updateLine(index, 'unit', e.target.value)
                                                }
                                                required
                                            />
                                            {errors[`items.${index}.unit`] && (
                                                <p className="text-sm text-red-600">
                                                    {errors[`items.${index}.unit`]}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Qty</Label>
                                            <Input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                placeholder="0"
                                                value={item.budgeted_qty}
                                                onChange={(e) =>
                                                    updateLine(index, 'budgeted_qty', e.target.value)
                                                }
                                                required
                                            />
                                            {errors[`items.${index}.budgeted_qty`] && (
                                                <p className="text-sm text-red-600">
                                                    {errors[`items.${index}.budgeted_qty`]}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Rate</Label>
                                            <AmountInput
                                                min="0"
                                                placeholder="0.00"
                                                value={item.unit_rate}
                                                onValueChange={(v) =>
                                                    updateLine(index, 'unit_rate', v)
                                                }
                                                required
                                            />
                                            {errors[`items.${index}.unit_rate`] && (
                                                <p className="text-sm text-red-600">
                                                    {errors[`items.${index}.unit_rate`]}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <p className="text-right text-sm text-slate-600">
                                        Amount:{' '}
                                        {formatCurrency(
                                            (parseFloat(item.budgeted_qty) || 0) *
                                                (parseFloat(item.unit_rate) || 0),
                                        )}
                                    </p>
                                </div>
                            ))}
                            {errors.items && (
                                <p className="text-sm text-red-600">{errors.items}</p>
                            )}
                            <p className="text-right text-sm font-medium text-slate-900">
                                Total: {formatCurrency(lineTotal)}
                            </p>
                        </div>
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link href={`/projects/${project.id}/boq`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save BOQ Items'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
