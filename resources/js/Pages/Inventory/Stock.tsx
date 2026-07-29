import AppShell from '@/Components/Layout/AppShell';
import InventoryNav from '@/Components/Inventory/InventoryNav';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatQuantity } from '@/lib/formatters';
import {
    InventoryItem,
    ListingFilters,
    PageProps,
    Paginated,
    Project,
    StockBalance,
    StockLocation,
    User,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, MapPin, PackagePlus, SlidersHorizontal } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface StockProps extends PageProps {
    balances: Paginated<StockBalance>;
    filters: ListingFilters & { project_id?: string };
    low_stock_count: number;
    inventory_items: InventoryItem[];
    stock_locations: StockLocation[];
    projects: Pick<Project, 'id' | 'code' | 'name'>[];
    recipients: Pick<User, 'id' | 'name' | 'email'>[];
}

type DialogKind = 'receive' | 'adjust' | 'transfer' | 'location' | null;

export default function Stock() {
    const { balances, filters, low_stock_count, inventory_items, stock_locations, projects } =
        usePage<StockProps>().props;
    const rows = balances.data ?? [];
    const [dialog, setDialog] = useState<DialogKind>(null);

    const receiveForm = useForm({
        inventory_item_id: '',
        stock_location_id: '',
        quantity: '',
        unit_cost: '',
    });

    const adjustForm = useForm({
        inventory_item_id: '',
        stock_location_id: '',
        new_quantity: '',
        reason: '',
    });

    const transferForm = useForm({
        inventory_item_id: '',
        from_location_id: '',
        to_location_id: '',
        quantity: '',
    });

    const locationForm = useForm({
        name: '',
        project_id: '',
    });

    function formFor(kind: Exclude<DialogKind, null>) {
        if (kind === 'receive') return receiveForm;
        if (kind === 'adjust') return adjustForm;
        if (kind === 'location') return locationForm;
        return transferForm;
    }

    function openDialog(kind: Exclude<DialogKind, null>) {
        formFor(kind).clearErrors();
        setDialog(kind);
    }

    function closeDialog() {
        if (!dialog) return;
        if (!confirmDiscardIfDirty(formFor(dialog).isDirty)) {
            return;
        }
        setDialog(null);
        receiveForm.reset();
        adjustForm.reset();
        transferForm.reset();
        locationForm.reset();
        receiveForm.clearErrors();
        adjustForm.clearErrors();
        transferForm.clearErrors();
        locationForm.clearErrors();
    }

    function submitReceive(e: FormEvent) {
        e.preventDefault();
        receiveForm.post('/inventory/receive', {
            onSuccess: () => {
                receiveForm.reset();
                setDialog(null);
            },
        });
    }

    function submitAdjust(e: FormEvent) {
        e.preventDefault();
        adjustForm.post('/inventory/adjust', {
            onSuccess: () => {
                adjustForm.reset();
                setDialog(null);
            },
        });
    }

    function submitTransfer(e: FormEvent) {
        e.preventDefault();
        transferForm.post('/inventory/transfer', {
            onSuccess: () => {
                transferForm.reset();
                setDialog(null);
            },
        });
    }

    function submitLocation(e: FormEvent) {
        e.preventDefault();
        locationForm.post('/inventory/locations', {
            onSuccess: () => {
                locationForm.reset();
                setDialog(null);
            },
        });
    }

    return (
        <AppShell title="On Hand">
            <Head title="On Hand" />
            <div className="space-y-6">
                <PageHeader
                    title="2. On hand"
                    description={
                        low_stock_count > 0
                            ? `${low_stock_count} item(s) below reorder point. Receive new stock, move it between stores, or correct counts.`
                            : 'Receive new stock onto the shelf, move it between stores, or correct physical counts.'
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button onClick={() => openDialog('receive')}>
                                <PackagePlus className="mr-2 h-4 w-4" />
                                Receive stock
                            </Button>
                            <Button variant="outline" onClick={() => openDialog('location')}>
                                <MapPin className="mr-2 h-4 w-4" />
                                Add location
                            </Button>
                            <Button variant="outline" onClick={() => openDialog('transfer')}>
                                <ArrowLeftRight className="mr-2 h-4 w-4" />
                                Transfer
                            </Button>
                            <Button variant="outline" onClick={() => openDialog('adjust')}>
                                <SlidersHorizontal className="mr-2 h-4 w-4" />
                                Correct count
                            </Button>
                        </div>
                    }
                />
                <InventoryNav active="balances" />

                <ListToolbar
                    baseUrl="/inventory/balances"
                    filters={filters}
                    searchPlaceholder="Search item, code, location…"
                    sortOptions={[
                        { value: 'updated_at', label: 'Last updated' },
                        { value: 'quantity_on_hand', label: 'Quantity on hand' },
                        { value: 'average_cost', label: 'Average cost' },
                    ]}
                    textFilters={[{ key: 'project_id', label: 'Project ID', placeholder: 'Project ID' }]}
                />

                <DataPanel title="Quantity by location" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Item</th>
                                <th className="px-6 py-3 font-medium">Code</th>
                                <th className="px-6 py-3 font-medium">Location</th>
                                <th className="px-6 py-3 text-right font-medium">Qty on hand</th>
                                <th className="px-6 py-3 text-right font-medium">Avg cost</th>
                                <th className="px-6 py-3 text-right font-medium">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        Nothing on hand yet.{' '}
                                        {stock_locations.length === 0 ? (
                                            <>
                                                First{' '}
                                                <button
                                                    type="button"
                                                    className="text-blue-700 underline"
                                                    onClick={() => openDialog('location')}
                                                >
                                                    add a location
                                                </button>
                                                , then receive stock.
                                            </>
                                        ) : inventory_items.length === 0 ? (
                                            <>
                                                First{' '}
                                                <Link
                                                    href="/inventory/items"
                                                    className="text-blue-700 underline"
                                                >
                                                    create an item
                                                </Link>
                                                , then receive stock.
                                            </>
                                        ) : (
                                            <>Use Receive stock to put quantity on the shelf.</>
                                        )}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((bal) => {
                                    const location =
                                        bal.location ??
                                        (bal as StockBalance & {
                                            stock_location?: { id: number; name: string };
                                        }).stock_location;

                                    return (
                                        <tr key={bal.id}>
                                            <td className="px-6 py-4 font-medium">
                                                {bal.inventory_item?.name}
                                            </td>
                                            <td className="px-6 py-4 font-mono text-slate-600">
                                                {bal.inventory_item?.code}
                                            </td>
                                            <td className="px-6 py-4">{location?.name ?? '—'}</td>
                                            <td className="px-6 py-4 text-right">
                                                {formatQuantity(bal.quantity_on_hand)} {bal.inventory_item?.unit}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                {formatCurrency(bal.average_cost)}
                                            </td>
                                            <td className="px-6 py-4 text-right font-medium">
                                                {formatCurrency(
                                                    parseFloat(bal.quantity_on_hand) *
                                                        parseFloat(bal.average_cost),
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={balances} />
                </DataPanel>
            </div>

            <Dialog
                open={dialog === 'location'}
                onOpenChange={(next) => (next ? openDialog('location') : closeDialog())}
                title="Add location"
                description="Create a store or site yard. Locations are tied to a project."
            >
                <form onSubmit={submitLocation} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="location-name">Location name</Label>
                        <Input
                            id="location-name"
                            value={locationForm.data.name}
                            onChange={(e) => locationForm.setData('name', e.target.value)}
                            placeholder="Main Site Store"
                            required
                            autoFocus
                        />
                        {locationForm.errors.name && (
                            <p className="text-sm text-red-600">{locationForm.errors.name}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="location-project">Project</Label>
                        <select
                            id="location-project"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={locationForm.data.project_id}
                            onChange={(e) => locationForm.setData('project_id', e.target.value)}
                            required
                        >
                            <option value="">Select project</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.code} — {project.name}
                                </option>
                            ))}
                        </select>
                        {locationForm.errors.project_id && (
                            <p className="text-sm text-red-600">{locationForm.errors.project_id}</p>
                        )}
                        {projects.length === 0 && (
                            <p className="text-xs text-amber-700">
                                No projects found. Create a project first, then add a store location.
                            </p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={locationForm.processing}
                        submitLabel="Create location"
                        processingLabel="Creating…"
                    />
                </form>
            </Dialog>

            <Dialog
                open={dialog === 'receive'}
                onOpenChange={(next) => (next ? openDialog('receive') : closeDialog())}
                title="Receive stock"
                description="Add quantity onto a location (purchases, deliveries, opening stock)."
            >
                <form onSubmit={submitReceive} className="space-y-4">
                    <ItemSelect
                        id="receive-item"
                        value={receiveForm.data.inventory_item_id}
                        items={inventory_items}
                        onChange={(v) => receiveForm.setData('inventory_item_id', v)}
                        error={receiveForm.errors.inventory_item_id}
                    />
                    <LocationSelect
                        id="receive-location"
                        label="Location"
                        value={receiveForm.data.stock_location_id}
                        locations={stock_locations}
                        onChange={(v) => receiveForm.setData('stock_location_id', v)}
                        error={receiveForm.errors.stock_location_id}
                        onAddLocation={() => openDialog('location')}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="receive-qty">Quantity received</Label>
                            <Input
                                id="receive-qty"
                                type="number"
                                step="0.001"
                                min="0"
                                value={receiveForm.data.quantity}
                                onChange={(e) => receiveForm.setData('quantity', e.target.value)}
                                required
                            />
                            {receiveForm.errors.quantity && (
                                <p className="text-sm text-red-600">{receiveForm.errors.quantity}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="receive-cost">Unit cost (optional)</Label>
                            <AmountInput
                                id="receive-cost"
                                min="0"
                                value={receiveForm.data.unit_cost}
                                onValueChange={(v) => receiveForm.setData('unit_cost', v)}
                                placeholder="0"
                            />
                            {receiveForm.errors.unit_cost && (
                                <p className="text-sm text-red-600">{receiveForm.errors.unit_cost}</p>
                            )}
                        </div>
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={receiveForm.processing}
                        submitLabel="Receive"
                        processingLabel="Receiving…"
                    />
                </form>
            </Dialog>

            <Dialog
                open={dialog === 'adjust'}
                onOpenChange={(next) => (next ? openDialog('adjust') : closeDialog())}
                title="Correct count"
                description="Use only when the physical count differs from the system. Prefer Receive for new stock."
            >
                <form onSubmit={submitAdjust} className="space-y-4">
                    <ItemSelect
                        id="adjust-item"
                        value={adjustForm.data.inventory_item_id}
                        items={inventory_items}
                        onChange={(v) => adjustForm.setData('inventory_item_id', v)}
                        error={adjustForm.errors.inventory_item_id}
                    />
                    <LocationSelect
                        id="adjust-location"
                        label="Location"
                        value={adjustForm.data.stock_location_id}
                        locations={stock_locations}
                        onChange={(v) => adjustForm.setData('stock_location_id', v)}
                        error={adjustForm.errors.stock_location_id}
                        onAddLocation={() => openDialog('location')}
                    />
                    <div className="space-y-2">
                        <Label htmlFor="adjust-qty">Actual quantity on hand</Label>
                        <Input
                            id="adjust-qty"
                            type="number"
                            step="0.001"
                            min="0"
                            value={adjustForm.data.new_quantity}
                            onChange={(e) => adjustForm.setData('new_quantity', e.target.value)}
                            required
                        />
                        {adjustForm.errors.new_quantity && (
                            <p className="text-sm text-red-600">{adjustForm.errors.new_quantity}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="adjust-reason">Reason</Label>
                        <Input
                            id="adjust-reason"
                            value={adjustForm.data.reason}
                            onChange={(e) => adjustForm.setData('reason', e.target.value)}
                            placeholder="Physical count variance"
                            required
                        />
                        {adjustForm.errors.reason && (
                            <p className="text-sm text-red-600">{adjustForm.errors.reason}</p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={adjustForm.processing}
                        submitLabel="Save correction"
                        processingLabel="Saving…"
                    />
                </form>
            </Dialog>

            <Dialog
                open={dialog === 'transfer'}
                onOpenChange={(next) => (next ? openDialog('transfer') : closeDialog())}
                title="Transfer stock"
                description="Move quantity from one store/location to another."
            >
                <form onSubmit={submitTransfer} className="space-y-4">
                    <ItemSelect
                        id="transfer-item"
                        value={transferForm.data.inventory_item_id}
                        items={inventory_items}
                        onChange={(v) => transferForm.setData('inventory_item_id', v)}
                        error={transferForm.errors.inventory_item_id}
                    />
                    <LocationSelect
                        id="transfer-from"
                        label="From location"
                        value={transferForm.data.from_location_id}
                        locations={stock_locations}
                        onChange={(v) => transferForm.setData('from_location_id', v)}
                        error={transferForm.errors.from_location_id}
                        onAddLocation={() => openDialog('location')}
                    />
                    <LocationSelect
                        id="transfer-to"
                        label="To location"
                        value={transferForm.data.to_location_id}
                        locations={stock_locations}
                        onChange={(v) => transferForm.setData('to_location_id', v)}
                        error={transferForm.errors.to_location_id}
                        onAddLocation={() => openDialog('location')}
                    />
                    <div className="space-y-2">
                        <Label htmlFor="transfer-qty">Quantity</Label>
                        <Input
                            id="transfer-qty"
                            type="number"
                            step="0.001"
                            min="0"
                            value={transferForm.data.quantity}
                            onChange={(e) => transferForm.setData('quantity', e.target.value)}
                            required
                        />
                        {transferForm.errors.quantity && (
                            <p className="text-sm text-red-600">{transferForm.errors.quantity}</p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={transferForm.processing}
                        submitLabel="Transfer"
                        processingLabel="Transferring…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}

function ItemSelect({
    id,
    value,
    items,
    onChange,
    error,
}: {
    id: string;
    value: string;
    items: InventoryItem[];
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>Item</Label>
            <select
                id={id}
                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                required
            >
                <option value="">Select item</option>
                {items.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.code} — {item.name}
                    </option>
                ))}
            </select>
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

function LocationSelect({
    id,
    label,
    value,
    locations,
    onChange,
    error,
    onAddLocation,
}: {
    id: string;
    label: string;
    value: string;
    locations: StockLocation[];
    onChange: (value: string) => void;
    error?: string;
    onAddLocation?: () => void;
}) {
    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <Label htmlFor={id}>{label}</Label>
                {onAddLocation && (
                    <button
                        type="button"
                        className="text-xs font-medium text-blue-700 hover:underline dark:text-blue-300"
                        onClick={onAddLocation}
                    >
                        Add location
                    </button>
                )}
            </div>
            <select
                id={id}
                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                required
            >
                <option value="">
                    {locations.length === 0 ? 'No locations yet' : 'Select location'}
                </option>
                {locations.map((loc) => (
                    <option key={loc.id} value={loc.id}>
                        {loc.name}
                    </option>
                ))}
            </select>
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}
