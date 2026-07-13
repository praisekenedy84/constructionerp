import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { BoqItem, FulfillmentType, PageProps, Project } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface RequisitionsCreateProps extends PageProps {
    projects: Project[];
    boqItems: BoqItem[];
}

const fulfillmentTypes: { value: FulfillmentType; label: string }[] = [
    { value: 'cash_disbursement', label: 'Cash Disbursement' },
    { value: 'stock_issue', label: 'Stock Issue' },
    { value: 'direct_supplier_payment', label: 'Direct Supplier Payment' },
];

export default function RequisitionsCreate() {
    const { projects, boqItems } = usePage<RequisitionsCreateProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        project_id: '',
        boq_item_id: '',
        department: '',
        fulfillment_type: 'cash_disbursement' as FulfillmentType,
        items: [{ description: '', quantity: '', unit_cost: '' }],
    });

    const selectedBoq = boqItems.find((b) => String(b.id) === data.boq_item_id);

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/requisitions');
    }

    function addLine() {
        setData('items', [...data.items, { description: '', quantity: '', unit_cost: '' }]);
    }

    function updateLine(index: number, field: string, value: string) {
        const items = [...data.items];
        items[index] = { ...items[index], [field]: value };
        setData('items', items);
    }

    const lineTotal = data.items.reduce(
        (sum, item) => sum + (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_cost) || 0),
        0,
    );

    return (
        <AppShell title="New Requisition">
            <Head title="New Requisition" />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title="Create Requisition"
                    description="Select a BOQ item and check available quantity before submitting."
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Requisition Details">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Project</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.project_id}
                                    onChange={(e) => setData('project_id', e.target.value)}
                                    required
                                >
                                    <option value="">Select project</option>
                                    {projects.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.code} — {p.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.project_id && (
                                    <p className="text-sm text-red-600">{errors.project_id}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>Department</Label>
                                <Input
                                    value={data.department}
                                    onChange={(e) => setData('department', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label>BOQ Item</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.boq_item_id}
                                    onChange={(e) => setData('boq_item_id', e.target.value)}
                                    required
                                >
                                    <option value="">Select BOQ item</option>
                                    {boqItems.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.description} — Available: {item.available_qty}{' '}
                                            {item.unit}
                                        </option>
                                    ))}
                                </select>
                                {selectedBoq && (
                                    <p className="text-sm text-green-700">
                                        Available: {selectedBoq.available_qty} {selectedBoq.unit} ·
                                        Rate: {formatCurrency(selectedBoq.unit_rate)}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label>Fulfillment Type</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.fulfillment_type}
                                    onChange={(e) =>
                                        setData('fulfillment_type', e.target.value as FulfillmentType)
                                    }
                                >
                                    {fulfillmentTypes.map((ft) => (
                                        <option key={ft.value} value={ft.value}>
                                            {ft.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </DataPanel>

                    <DataPanel
                        title="Line Items"
                        actions={
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                Add Line
                            </Button>
                        }
                    >
                        <div className="space-y-3">
                            {data.items.map((item, index) => (
                                <div key={index} className="grid gap-3 sm:grid-cols-4">
                                    <Input
                                        placeholder="Description"
                                        value={item.description}
                                        onChange={(e) => updateLine(index, 'description', e.target.value)}
                                        className="sm:col-span-2"
                                    />
                                    <Input
                                        type="number"
                                        step="0.0001"
                                        placeholder="Qty"
                                        value={item.quantity}
                                        onChange={(e) => updateLine(index, 'quantity', e.target.value)}
                                    />
                                    <Input
                                        type="number"
                                        step="0.01"
                                        placeholder="Unit cost"
                                        value={item.unit_cost}
                                        onChange={(e) => updateLine(index, 'unit_cost', e.target.value)}
                                    />
                                </div>
                            ))}
                            <p className="text-right text-sm font-medium text-slate-900">
                                Total: {formatCurrency(lineTotal)}
                            </p>
                        </div>
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link href="/requisitions">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating…' : 'Create Requisition'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
