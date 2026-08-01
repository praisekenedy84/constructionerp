import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatQuantity } from '@/lib/formatters';
import {
    BoqItem,
    Department,
    FulfillmentType,
    InventoryItem,
    PageProps,
    Project,
    Requisition,
    RequisitionAddressedTo,
    RequisitionCategory,
    RequisitionItem,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface BoqItemOption extends Pick<BoqItem, 'id' | 'description' | 'unit' | 'unit_rate' | 'available_qty'> {
    project_id: number | null;
}

interface RequisitionsCreateProps extends PageProps {
    projects: Project[];
    boqItems: BoqItemOption[];
    inventoryItems: InventoryItem[];
    categories: RequisitionCategory[];
    departments: Department[];
    requisition?: Requisition;
}

type LineSource = 'new' | 'catalog';

interface LineItemForm {
    key: string;
    source: LineSource;
    inventory_item_id: string;
    description: string;
    unit: string;
    quantity: string;
    unit_cost: string;
}

let lineKeySeq = 0;

function nextLineKey(): string {
    lineKeySeq += 1;
    return `line-${Date.now()}-${lineKeySeq}`;
}

const emptyLine = (): LineItemForm => ({
    key: nextLineKey(),
    source: 'new',
    inventory_item_id: '',
    description: '',
    unit: '',
    quantity: '',
    unit_cost: '',
});

function lineFromItem(item: RequisitionItem): LineItemForm {
    return {
        ...emptyLine(),
        source: item.inventory_item_id ? 'catalog' : 'new',
        inventory_item_id: item.inventory_item_id ? String(item.inventory_item_id) : '',
        description: item.description ?? '',
        unit: item.unit ?? '',
        quantity: item.quantity ?? '',
        unit_cost: item.unit_cost ?? '',
    };
}

function defaultFulfillment(addressedTo: RequisitionAddressedTo): FulfillmentType {
    return addressedTo === 'storekeeper' ? 'stock_issue' : 'cash_disbursement';
}

function fulfillmentOptions(addressedTo: RequisitionAddressedTo): {
    value: FulfillmentType;
    label: string;
}[] {
    if (addressedTo === 'storekeeper') {
        return [{ value: 'stock_issue', label: 'Stock Issue' }];
    }

    return [
        { value: 'cash_disbursement', label: 'Cash Disbursement' },
        { value: 'direct_supplier_payment', label: 'Direct Supplier Payment' },
    ];
}

function lineEstimate(item: LineItemForm): number {
    return (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_cost) || 0);
}

export default function RequisitionsCreate() {
    const { projects, boqItems, inventoryItems, categories, departments, requisition } =
        usePage<RequisitionsCreateProps>().props;
    const isEditing = Boolean(requisition);

    const initialCategory =
        categories.find((category) => category.id === requisition?.requisition_category_id) ??
        categories[0] ??
        null;
    const initialDepartment =
        departments.find((department) => department.id === requisition?.department_id) ??
        departments.find((department) => department.name === requisition?.department) ??
        departments[0] ??
        null;
    const initialAddressedTo = (requisition?.addressed_to ?? 'finance') as RequisitionAddressedTo;

    const { data, setData, post, put, processing, errors, transform } = useForm({
        scope: requisition
            ? requisition.project_id
                ? 'project'
                : 'organization'
            : 'project',
        project_id: requisition?.project_id ? String(requisition.project_id) : '',
        boq_item_id: requisition?.boq_item_id ? String(requisition.boq_item_id) : '',
        department_id: initialDepartment ? String(initialDepartment.id) : '',
        requisition_category_id: initialCategory ? String(initialCategory.id) : '',
        addressed_to: initialAddressedTo,
        fulfillment_type: (requisition?.fulfillment_type ??
            defaultFulfillment(initialAddressedTo)) as FulfillmentType,
        items:
            requisition?.items && requisition.items.length > 0
                ? requisition.items.map(lineFromItem)
                : [emptyLine()],
    });

    transform((form) => ({
        project_id: form.scope === 'project' ? form.project_id || null : null,
        boq_item_id: form.scope === 'project' ? form.boq_item_id || null : null,
        department_id: form.department_id,
        requisition_category_id: form.requisition_category_id,
        addressed_to: form.addressed_to,
        fulfillment_type: form.fulfillment_type,
        items: form.items.map((item) => ({
            inventory_item_id:
                item.source === 'catalog' && item.inventory_item_id ? item.inventory_item_id : null,
            description: item.description,
            unit: item.unit || null,
            quantity: item.quantity,
            unit_cost: item.unit_cost,
        })),
    }));

    const isOrganization = data.scope === 'organization';
    const projectBoqItems = boqItems.filter(
        (item) => !data.project_id || String(item.project_id) === data.project_id,
    );
    const selectedBoq = projectBoqItems.find((b) => String(b.id) === data.boq_item_id);
    const availableFulfillments = fulfillmentOptions(data.addressed_to);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (isEditing && requisition) {
            put(`/requisitions/${requisition.id}`);
            return;
        }
        post('/requisitions');
    }

    function addLine(e?: FormEvent) {
        e?.preventDefault();
        e?.stopPropagation();
        setData('items', [...data.items, emptyLine()]);
    }

    function removeLine(index: number) {
        if (data.items.length <= 1) {
            return;
        }
        setData(
            'items',
            data.items.filter((_, i) => i !== index),
        );
    }

    function updateLine(index: number, field: keyof LineItemForm, value: string) {
        const items = data.items.map((item, i) => {
            if (i !== index) {
                return item;
            }

            const next = { ...item, [field]: value };

            if (field === 'source' && value === 'new') {
                next.inventory_item_id = '';
            }

            if (field === 'inventory_item_id') {
                const catalogItem = inventoryItems.find((c) => String(c.id) === value);
                if (catalogItem) {
                    next.description = catalogItem.name;
                    next.unit = catalogItem.unit;
                    next.source = 'catalog';
                }
            }

            return next;
        });

        setData('items', items);
    }

    function setCategory(categoryId: string) {
        setData('requisition_category_id', categoryId);
    }

    function setAddressedTo(value: RequisitionAddressedTo) {
        setData({
            ...data,
            addressed_to: value,
            fulfillment_type: defaultFulfillment(value),
        });
    }

    const estimatedTotal = data.items.reduce((sum, item) => sum + lineEstimate(item), 0);

    return (
        <AppShell title={isEditing ? 'Edit Requisition' : 'New Requisition'}>
            <Head title={isEditing ? `Edit ${requisition?.requisition_no}` : 'New Requisition'} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={isEditing ? `Edit ${requisition?.requisition_no}` : 'Create Requisition'}
                    description={
                        isEditing
                            ? 'Update this draft before submitting it for review.'
                            : 'Choose a project (direct expense) or organization (overhead), then add request lines.'
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Requisition Details">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label>Request scope</Label>
                                <div className="flex flex-wrap gap-3">
                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="radio"
                                            name="scope"
                                            value="project"
                                            checked={data.scope === 'project'}
                                            onChange={() =>
                                                setData({
                                                    ...data,
                                                    scope: 'project',
                                                })
                                            }
                                        />
                                        Project (direct expense on fulfill)
                                    </label>
                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="radio"
                                            name="scope"
                                            value="organization"
                                            checked={data.scope === 'organization'}
                                            onChange={() =>
                                                setData({
                                                    ...data,
                                                    scope: 'organization',
                                                    project_id: '',
                                                    boq_item_id: '',
                                                })
                                            }
                                        />
                                        Organization (overhead on fulfill)
                                    </label>
                                </div>
                                <p className="text-xs text-slate-500">
                                    {isOrganization
                                        ? 'Paid from organization cash on hand and recorded as an indirect / overhead expense when fulfilled.'
                                        : 'Charged to a project cash float and recorded as a direct expense when fulfilled.'}
                                </p>
                            </div>
                            {!isOrganization && (
                                <div className="space-y-2">
                                    <Label>Project</Label>
                                    <select
                                        className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                        value={data.project_id}
                                        onChange={(e) => {
                                            setData({
                                                ...data,
                                                project_id: e.target.value,
                                                boq_item_id: '',
                                            });
                                        }}
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
                            )}
                            <div className="space-y-2">
                                <Label>Department</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.department_id}
                                    onChange={(e) => setData('department_id', e.target.value)}
                                    required
                                >
                                    {departments.length === 0 && (
                                        <option value="">No departments defined</option>
                                    )}
                                    {departments.map((department) => (
                                        <option key={department.id} value={department.id}>
                                            {department.name}
                                            {!department.is_active ? ' (inactive)' : ''}
                                        </option>
                                    ))}
                                </select>
                                {errors.department_id && (
                                    <p className="text-sm text-red-600">{errors.department_id}</p>
                                )}
                                {errors.department && !errors.department_id && (
                                    <p className="text-sm text-red-600">{errors.department}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>Category</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.requisition_category_id}
                                    onChange={(e) => setCategory(e.target.value)}
                                    required
                                >
                                    {categories.length === 0 && (
                                        <option value="">No categories defined</option>
                                    )}
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                            {!category.is_active ? ' (inactive)' : ''}
                                        </option>
                                    ))}
                                </select>
                                {errors.requisition_category_id && (
                                    <p className="text-sm text-red-600">
                                        {errors.requisition_category_id}
                                    </p>
                                )}
                                {categories.length === 0 && (
                                    <p className="text-sm text-amber-700">
                                        Define categories under Requisitions → Categories before
                                        creating a request.
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>Addressed to</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.addressed_to}
                                    onChange={(e) =>
                                        setAddressedTo(e.target.value as RequisitionAddressedTo)
                                    }
                                    required
                                >
                                    <option value="finance">Finance (pay from cash on hand)</option>
                                    <option value="storekeeper">
                                        Storekeeper (issue from inventory)
                                    </option>
                                </select>
                                <p className="text-xs text-slate-500">
                                    {data.addressed_to === 'storekeeper'
                                        ? 'Fulfillment will reduce inventory stock and still record an expense.'
                                        : isOrganization
                                          ? 'Fulfillment will reduce organization cash on hand and record overhead.'
                                          : 'Fulfillment will reduce project cash on hand and record a direct expense.'}
                                </p>
                                {errors.addressed_to && (
                                    <p className="text-sm text-red-600">{errors.addressed_to}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>How should it be fulfilled?</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.fulfillment_type}
                                    onChange={(e) =>
                                        setData('fulfillment_type', e.target.value as FulfillmentType)
                                    }
                                >
                                    {availableFulfillments.map((ft) => (
                                        <option key={ft.value} value={ft.value}>
                                            {ft.label}
                                        </option>
                                    ))}
                                </select>
                                {errors.fulfillment_type && (
                                    <p className="text-sm text-red-600">{errors.fulfillment_type}</p>
                                )}
                            </div>
                            {!isOrganization && (
                                <div className="space-y-2 sm:col-span-2">
                                    <Label>BOQ Item (optional cost allocation)</Label>
                                    <select
                                        className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                        value={data.boq_item_id}
                                        onChange={(e) => setData('boq_item_id', e.target.value)}
                                        disabled={!data.project_id}
                                    >
                                        <option value="">No BOQ link — free request</option>
                                        {projectBoqItems.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.description} — Available:{' '}
                                                {formatQuantity(item.available_qty)} {item.unit}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedBoq && (
                                        <p className="text-sm text-green-700">
                                            Available: {formatQuantity(selectedBoq.available_qty)}{' '}
                                            {selectedBoq.unit} · Rate:{' '}
                                            {formatCurrency(selectedBoq.unit_rate)}
                                        </p>
                                    )}
                                    {errors.boq_item_id && (
                                        <p className="text-sm text-red-600">{errors.boq_item_id}</p>
                                    )}
                                </div>
                            )}
                        </div>
                    </DataPanel>

                    <DataPanel
                        title="Request Lines"
                        actions={
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                Add another line
                            </Button>
                        }
                    >
                        <p className="mb-4 text-sm text-slate-500">
                            Add description, quantity, and unit cost for each line. Optionally pick from
                            the inventory catalog.
                        </p>
                        <div className="space-y-4">
                            {data.items.map((item, index) => (
                                <div
                                    key={item.key}
                                    className="space-y-3 rounded-md border border-slate-200 p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                            Line {index + 1} of {data.items.length}
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

                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={item.source === 'new' ? 'default' : 'outline'}
                                            onClick={() => updateLine(index, 'source', 'new')}
                                        >
                                            New item
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={item.source === 'catalog' ? 'default' : 'outline'}
                                            onClick={() => updateLine(index, 'source', 'catalog')}
                                        >
                                            From catalog
                                        </Button>
                                    </div>

                                    {item.source === 'catalog' && (
                                        <div className="space-y-2">
                                            <Label>Catalog item</Label>
                                            <select
                                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                value={item.inventory_item_id}
                                                onChange={(e) =>
                                                    updateLine(index, 'inventory_item_id', e.target.value)
                                                }
                                            >
                                                <option value="">Select inventory item</option>
                                                {inventoryItems.map((catalogItem) => (
                                                    <option key={catalogItem.id} value={catalogItem.id}>
                                                        {catalogItem.code} — {catalogItem.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}

                                    <div className="grid gap-3 sm:grid-cols-6">
                                        <div className="space-y-2 sm:col-span-3">
                                            <Label>Description</Label>
                                            <Input
                                                placeholder="What is needed"
                                                value={item.description}
                                                onChange={(e) =>
                                                    updateLine(index, 'description', e.target.value)
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Unit</Label>
                                            <Input
                                                placeholder="bag, L, pcs, day"
                                                value={item.unit}
                                                onChange={(e) =>
                                                    updateLine(index, 'unit', e.target.value)
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Qty</Label>
                                            <Input
                                                type="number"
                                                step="0.001"
                                                value={item.quantity}
                                                onChange={(e) =>
                                                    updateLine(index, 'quantity', e.target.value)
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Unit cost</Label>
                                            <AmountInput
                                                value={item.unit_cost}
                                                onValueChange={(v) =>
                                                    updateLine(index, 'unit_cost', v)
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2 sm:col-span-6">
                                            <Label>Estimated line cost</Label>
                                            <p className="flex h-10 items-center rounded-md border border-slate-100 bg-slate-50 px-3 text-sm font-medium text-slate-900">
                                                {formatCurrency(lineEstimate(item))}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {errors.items && <p className="text-sm text-red-600">{errors.items}</p>}

                            <Button
                                type="button"
                                variant="outline"
                                className="w-full border-dashed"
                                onClick={addLine}
                            >
                                + Add another line
                            </Button>

                            <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                                <p className="text-sm text-slate-500">
                                    Estimated request total ({data.items.length}{' '}
                                    {data.items.length === 1 ? 'line' : 'lines'})
                                </p>
                                <p className="text-lg font-semibold text-slate-900">
                                    {formatCurrency(estimatedTotal)}
                                </p>
                            </div>
                        </div>
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={
                                isEditing && requisition
                                    ? `/requisitions/${requisition.id}`
                                    : '/requisitions'
                            }
                        >
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? isEditing
                                    ? 'Saving…'
                                    : 'Creating…'
                                : isEditing
                                  ? 'Save Changes'
                                  : 'Create Requisition'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
