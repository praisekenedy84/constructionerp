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
    FulfillmentType,
    InventoryItem,
    PageProps,
    Project,
    Requisition,
    RequisitionAddressedTo,
    RequisitionItem,
    RequisitionResourceType,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ResourceTypeOption {
    value: RequisitionResourceType;
    label: string;
}

interface BoqItemOption extends Pick<BoqItem, 'id' | 'description' | 'unit' | 'unit_rate' | 'available_qty'> {
    project_id: number | null;
}

interface RequisitionsCreateProps extends PageProps {
    projects: Project[];
    boqItems: BoqItemOption[];
    inventoryItems: InventoryItem[];
    resourceTypes: ResourceTypeOption[];
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
    workers: string;
    days: string;
    rate_per_day: string;
    estimated_amount: string;
    duration: string;
    duration_unit: 'day' | 'hour' | 'week';
    rate: string;
    trips: string;
    cost_per_trip: string;
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
    workers: '',
    days: '',
    rate_per_day: '',
    estimated_amount: '',
    duration: '',
    duration_unit: 'day',
    rate: '',
    trips: '',
    cost_per_trip: '',
});

function lineFromItem(item: RequisitionItem): LineItemForm {
    const details = item.details ?? {};
    const durationUnit = details.duration_unit;
    const normalizedUnit =
        durationUnit === 'hour' || durationUnit === 'week' || durationUnit === 'day'
            ? durationUnit
            : 'day';

    return {
        ...emptyLine(),
        source: item.inventory_item_id ? 'catalog' : 'new',
        inventory_item_id: item.inventory_item_id ? String(item.inventory_item_id) : '',
        description: item.description ?? '',
        unit: item.unit ?? '',
        quantity: item.quantity ?? '',
        unit_cost: item.unit_cost ?? '',
        workers: details.workers ?? '',
        days: details.days ?? '',
        rate_per_day: details.rate_per_day ?? '',
        estimated_amount: details.estimated_amount ?? item.unit_cost ?? '',
        duration: details.duration ?? item.quantity ?? '',
        duration_unit: normalizedUnit,
        rate: details.rate ?? item.unit_cost ?? '',
        trips: details.trips ?? item.quantity ?? '',
        cost_per_trip: details.cost_per_trip ?? item.unit_cost ?? '',
    };
}

function defaultAddressedTo(resourceType: RequisitionResourceType): RequisitionAddressedTo {
    if (resourceType === 'materials' || resourceType === 'fuel') {
        return 'storekeeper';
    }

    return 'finance';
}

function defaultFulfillment(
    resourceType: RequisitionResourceType,
    addressedTo: RequisitionAddressedTo,
): FulfillmentType {
    if (addressedTo === 'storekeeper') {
        return 'stock_issue';
    }
    if (resourceType === 'cash' || resourceType === 'labor') {
        return 'cash_disbursement';
    }
    return 'direct_supplier_payment';
}

function fulfillmentOptions(
    resourceType: RequisitionResourceType,
    addressedTo: RequisitionAddressedTo,
): {
    value: FulfillmentType;
    label: string;
}[] {
    if (addressedTo === 'storekeeper') {
        return [{ value: 'stock_issue', label: 'Stock Issue' }];
    }

    const financeOptions: { value: FulfillmentType; label: string }[] = [
        { value: 'cash_disbursement', label: 'Cash Disbursement' },
        { value: 'direct_supplier_payment', label: 'Direct Supplier Payment' },
    ];

    if (resourceType === 'materials' || resourceType === 'fuel') {
        return financeOptions;
    }

    return financeOptions;
}

function usesCatalog(resourceType: RequisitionResourceType): boolean {
    return resourceType === 'materials' || resourceType === 'fuel';
}

function lineEstimate(resourceType: RequisitionResourceType, item: LineItemForm): number {
    if (resourceType === 'labor') {
        return (
            (parseFloat(item.workers) || 0) *
            (parseFloat(item.days) || 0) *
            (parseFloat(item.rate_per_day) || 0)
        );
    }
    if (resourceType === 'cash') {
        return parseFloat(item.estimated_amount) || 0;
    }
    if (resourceType === 'equipment') {
        return (parseFloat(item.duration) || 0) * (parseFloat(item.rate) || 0);
    }
    if (resourceType === 'transport') {
        return (parseFloat(item.trips) || 0) * (parseFloat(item.cost_per_trip) || 0);
    }
    return (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_cost) || 0);
}

function panelTitle(resourceType: RequisitionResourceType): string {
    switch (resourceType) {
        case 'labor':
            return 'Labour Requirements';
        case 'cash':
            return 'Cash Request';
        case 'equipment':
            return 'Equipment Requirements';
        case 'transport':
            return 'Transport Requirements';
        case 'materials':
            return 'Material Lines';
        case 'fuel':
            return 'Fuel Lines';
        case 'services':
            return 'Service Lines';
        default:
            return 'Request Lines';
    }
}

function panelDescription(resourceType: RequisitionResourceType): string {
    switch (resourceType) {
        case 'labor':
            return 'Add one or more labour lines (e.g. casuals and skilled). Each line has workers, days, rate, and estimated cost.';
        case 'cash':
            return 'Add one or more cash purposes with estimated amounts.';
        case 'equipment':
            return 'Add one or more equipment lines with hire duration and rate.';
        case 'transport':
            return 'Add one or more transport lines with trips and cost per trip.';
        case 'materials':
        case 'fuel':
            return 'Add multiple catalog or new items. All lines must be the same resource type.';
        default:
            return 'Add multiple lines of this resource type. Estimated cost is calculated per line and totaled below.';
    }
}

function addLineLabel(resourceType: RequisitionResourceType): string {
    switch (resourceType) {
        case 'labor':
            return 'Add another labour line';
        case 'cash':
            return 'Add another cash line';
        case 'equipment':
            return 'Add another equipment line';
        case 'transport':
            return 'Add another transport line';
        case 'materials':
            return 'Add another material line';
        case 'fuel':
            return 'Add another fuel line';
        case 'services':
            return 'Add another service line';
        default:
            return 'Add another line';
    }
}

export default function RequisitionsCreate() {
    const { projects, boqItems, inventoryItems, resourceTypes, requisition } =
        usePage<RequisitionsCreateProps>().props;
    const isEditing = Boolean(requisition);

    const { data, setData, post, put, processing, errors, transform } = useForm({
        project_id: requisition ? String(requisition.project_id) : '',
        boq_item_id: requisition?.boq_item_id ? String(requisition.boq_item_id) : '',
        department: requisition?.department ?? '',
        resource_type: (requisition?.resource_type ?? 'materials') as RequisitionResourceType,
        addressed_to: (requisition?.addressed_to ??
            defaultAddressedTo(
                (requisition?.resource_type ?? 'materials') as RequisitionResourceType,
            )) as RequisitionAddressedTo,
        fulfillment_type: (requisition?.fulfillment_type ??
            defaultFulfillment(
                (requisition?.resource_type ?? 'materials') as RequisitionResourceType,
                (requisition?.addressed_to ??
                    defaultAddressedTo(
                        (requisition?.resource_type ?? 'materials') as RequisitionResourceType,
                    )) as RequisitionAddressedTo,
            )) as FulfillmentType,
        items:
            requisition?.items && requisition.items.length > 0
                ? requisition.items.map(lineFromItem)
                : [emptyLine()],
    });

    transform((form) => {
        const resourceType = form.resource_type;

        return {
            project_id: form.project_id,
            boq_item_id: form.boq_item_id || null,
            department: form.department,
            resource_type: resourceType,
            addressed_to: form.addressed_to,
            fulfillment_type: form.fulfillment_type,
            items: form.items.map((item) => {
                const inventoryItemId =
                    usesCatalog(resourceType) &&
                    item.source === 'catalog' &&
                    item.inventory_item_id
                        ? item.inventory_item_id
                        : null;

                if (resourceType === 'labor') {
                    return {
                        description: item.description,
                        workers: item.workers,
                        days: item.days,
                        rate_per_day: item.rate_per_day,
                    };
                }
                if (resourceType === 'cash') {
                    return {
                        description: item.description,
                        estimated_amount: item.estimated_amount,
                    };
                }
                if (resourceType === 'equipment') {
                    return {
                        description: item.description,
                        duration: item.duration,
                        duration_unit: item.duration_unit,
                        rate: item.rate,
                    };
                }
                if (resourceType === 'transport') {
                    return {
                        description: item.description,
                        trips: item.trips,
                        cost_per_trip: item.cost_per_trip,
                    };
                }

                return {
                    inventory_item_id: inventoryItemId,
                    description: item.description,
                    unit: item.unit || null,
                    quantity: item.quantity,
                    unit_cost: item.unit_cost,
                };
            }),
        };
    });

    const projectBoqItems = boqItems.filter(
        (item) => !data.project_id || String(item.project_id) === data.project_id,
    );
    const selectedBoq = projectBoqItems.find((b) => String(b.id) === data.boq_item_id);
    const availableFulfillments = fulfillmentOptions(data.resource_type, data.addressed_to);
    const canAddressStorekeeper =
        data.resource_type === 'materials' || data.resource_type === 'fuel';

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
        if (data.items.length === 1) {
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

    function setResourceType(value: RequisitionResourceType) {
        const addressedTo = defaultAddressedTo(value);
        setData({
            ...data,
            resource_type: value,
            addressed_to: addressedTo,
            fulfillment_type: defaultFulfillment(value, addressedTo),
            items: [emptyLine()],
        });
    }

    function setAddressedTo(value: RequisitionAddressedTo) {
        setData({
            ...data,
            addressed_to: value,
            fulfillment_type: defaultFulfillment(data.resource_type, value),
        });
    }

    const estimatedTotal = data.items.reduce(
        (sum, item) => sum + lineEstimate(data.resource_type, item),
        0,
    );

    return (
        <AppShell title={isEditing ? 'Edit Requisition' : 'New Requisition'}>
            <Head title={isEditing ? `Edit ${requisition?.requisition_no}` : 'New Requisition'} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={isEditing ? `Edit ${requisition?.requisition_no}` : 'Create Requisition'}
                    description={
                        isEditing
                            ? 'Update this draft before submitting it for review.'
                            : 'Choose what you need — the form adapts by resource type. Estimated cost is always calculated.'
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Requisition Details">
                        <div className="grid gap-4 sm:grid-cols-2">
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
                            <div className="space-y-2">
                                <Label>Department</Label>
                                <Input
                                    value={data.department}
                                    onChange={(e) => setData('department', e.target.value)}
                                    required
                                />
                                {errors.department && (
                                    <p className="text-sm text-red-600">{errors.department}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>What are you requesting?</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={data.resource_type}
                                    onChange={(e) =>
                                        setResourceType(e.target.value as RequisitionResourceType)
                                    }
                                    required
                                >
                                    {resourceTypes.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </select>
                                {errors.resource_type && (
                                    <p className="text-sm text-red-600">{errors.resource_type}</p>
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
                                    {canAddressStorekeeper && (
                                        <option value="storekeeper">
                                            Storekeeper (issue from inventory)
                                        </option>
                                    )}
                                </select>
                                <p className="text-xs text-slate-500">
                                    {data.addressed_to === 'storekeeper'
                                        ? 'Fulfillment will reduce inventory stock.'
                                        : 'Fulfillment will reduce finance cash on hand.'}
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
                        </div>
                    </DataPanel>

                    <DataPanel
                        title={panelTitle(data.resource_type)}
                        actions={
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                {addLineLabel(data.resource_type)}
                            </Button>
                        }
                    >
                        <p className="mb-4 text-sm text-slate-500">
                            {panelDescription(data.resource_type)}
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

                                    {usesCatalog(data.resource_type) && (
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
                                                variant={
                                                    item.source === 'catalog' ? 'default' : 'outline'
                                                }
                                                onClick={() => updateLine(index, 'source', 'catalog')}
                                            >
                                                From catalog
                                            </Button>
                                        </div>
                                    )}

                                    {usesCatalog(data.resource_type) && item.source === 'catalog' && (
                                        <div className="space-y-2">
                                            <Label>Catalog item</Label>
                                            <select
                                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                value={item.inventory_item_id}
                                                onChange={(e) =>
                                                    updateLine(
                                                        index,
                                                        'inventory_item_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">Select inventory item</option>
                                                {inventoryItems.map((catalogItem) => (
                                                    <option
                                                        key={catalogItem.id}
                                                        value={catalogItem.id}
                                                    >
                                                        {catalogItem.code} — {catalogItem.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}

                                    {data.resource_type === 'labor' && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Labour description / role</Label>
                                                <Input
                                                    placeholder="e.g. Casual workers for excavation"
                                                    value={item.description}
                                                    onChange={(e) =>
                                                        updateLine(index, 'description', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Number of workers</Label>
                                                <Input
                                                    type="number"
                                                    step="1"
                                                    min="1"
                                                    value={item.workers}
                                                    onChange={(e) =>
                                                        updateLine(index, 'workers', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Number of days</Label>
                                                <Input
                                                    type="number"
                                                    step="0.5"
                                                    min="0.5"
                                                    value={item.days}
                                                    onChange={(e) =>
                                                        updateLine(index, 'days', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Rate per worker / day</Label>
                                                <AmountInput
                                                    min="0"
                                                    value={item.rate_per_day}
                                                    onValueChange={(v) =>
                                                        updateLine(index, 'rate_per_day', v)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Estimated line cost</Label>
                                                <p className="flex h-10 items-center rounded-md border border-slate-100 bg-slate-50 px-3 text-sm font-medium text-slate-900">
                                                    {formatCurrency(lineEstimate('labor', item))}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {data.resource_type === 'cash' && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Purpose</Label>
                                                <Input
                                                    placeholder="e.g. Petty cash for local purchases"
                                                    value={item.description}
                                                    onChange={(e) =>
                                                        updateLine(index, 'description', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Estimated amount</Label>
                                                <AmountInput
                                                    min="0"
                                                    value={item.estimated_amount}
                                                    onValueChange={(v) =>
                                                        updateLine(index, 'estimated_amount', v)
                                                    }
                                                    required
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {data.resource_type === 'equipment' && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Equipment</Label>
                                                <Input
                                                    placeholder="e.g. Excavator, tipper truck"
                                                    value={item.description}
                                                    onChange={(e) =>
                                                        updateLine(index, 'description', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Duration</Label>
                                                <Input
                                                    type="number"
                                                    step="0.5"
                                                    min="0.5"
                                                    value={item.duration}
                                                    onChange={(e) =>
                                                        updateLine(index, 'duration', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Duration unit</Label>
                                                <select
                                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                    value={item.duration_unit}
                                                    onChange={(e) =>
                                                        updateLine(
                                                            index,
                                                            'duration_unit',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="hour">Hour</option>
                                                    <option value="day">Day</option>
                                                    <option value="week">Week</option>
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Rate per {item.duration_unit}</Label>
                                                <AmountInput
                                                    min="0"
                                                    value={item.rate}
                                                    onValueChange={(v) =>
                                                        updateLine(index, 'rate', v)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Estimated line cost</Label>
                                                <p className="flex h-10 items-center rounded-md border border-slate-100 bg-slate-50 px-3 text-sm font-medium text-slate-900">
                                                    {formatCurrency(lineEstimate('equipment', item))}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {data.resource_type === 'transport' && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Transport description</Label>
                                                <Input
                                                    placeholder="e.g. Aggregate haulage to site"
                                                    value={item.description}
                                                    onChange={(e) =>
                                                        updateLine(index, 'description', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Number of trips</Label>
                                                <Input
                                                    type="number"
                                                    step="1"
                                                    min="1"
                                                    value={item.trips}
                                                    onChange={(e) =>
                                                        updateLine(index, 'trips', e.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Cost per trip</Label>
                                                <AmountInput
                                                    min="0"
                                                    value={item.cost_per_trip}
                                                    onValueChange={(v) =>
                                                        updateLine(index, 'cost_per_trip', v)
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Estimated line cost</Label>
                                                <p className="flex h-10 items-center rounded-md border border-slate-100 bg-slate-50 px-3 text-sm font-medium text-slate-900">
                                                    {formatCurrency(lineEstimate('transport', item))}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {(data.resource_type === 'materials' ||
                                        data.resource_type === 'fuel' ||
                                        data.resource_type === 'services' ||
                                        data.resource_type === 'other') && (
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
                                                    placeholder="bag, L, pcs"
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
                                                    {formatCurrency(
                                                        lineEstimate(data.resource_type, item),
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                            {errors.items && (
                                <p className="text-sm text-red-600">{errors.items}</p>
                            )}

                            <Button
                                type="button"
                                variant="outline"
                                className="w-full border-dashed"
                                onClick={addLine}
                            >
                                + {addLineLabel(data.resource_type)}
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
                        <Link href={isEditing && requisition ? `/requisitions/${requisition.id}` : '/requisitions'}>
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
