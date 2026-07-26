import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import {
    InventoryItem,
    ListingFilters,
    PageProps,
    Paginated,
    StockBalance,
    StockLocation,
    User,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, SlidersHorizontal } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface StockProps extends PageProps {
    balances: Paginated<StockBalance>;
    filters: ListingFilters & { project_id?: string };
    low_stock_count: number;
    inventory_items: InventoryItem[];
    stock_locations: StockLocation[];
    recipients: Pick<User, 'id' | 'name' | 'email'>[];
}

type DialogKind = 'adjust' | 'transfer' | null;

export default function Stock() {
    const { balances, filters, low_stock_count, inventory_items, stock_locations } =
        usePage<StockProps>().props;
    const rows = balances.data ?? [];
    const [dialog, setDialog] = useState<DialogKind>(null);

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

    function openDialog(kind: Exclude<DialogKind, null>) {
        if (kind === 'adjust') {
            adjustForm.clearErrors();
        } else {
            transferForm.clearErrors();
        }
        setDialog(kind);
    }

    function closeDialog() {
        const dirty = dialog === 'adjust' ? adjustForm.isDirty : transferForm.isDirty;
        if (!confirmDiscardIfDirty(dirty)) {
            return;
        }
        setDialog(null);
        adjustForm.reset();
        transferForm.reset();
        adjustForm.clearErrors();
        transferForm.clearErrors();
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

    return (
        <AppShell title="Stock">
            <Head title="Stock Balances" />
            <div className="space-y-6">
                <PageHeader
                    title="Stock Balances"
                    description={`${low_stock_count} items below reorder point`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button onClick={() => openDialog('adjust')}>
                                <SlidersHorizontal className="mr-2 h-4 w-4" />
                                Adjust Stock
                            </Button>
                            <Button variant="outline" onClick={() => openDialog('transfer')}>
                                <ArrowLeftRight className="mr-2 h-4 w-4" />
                                Transfer Stock
                            </Button>
                        </div>
                    }
                />

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

                <DataPanel title="Stock by Location" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Item</th>
                                <th className="px-6 py-3 font-medium">Code</th>
                                <th className="px-6 py-3 font-medium">Location</th>
                                <th className="px-6 py-3 text-right font-medium">Qty on Hand</th>
                                <th className="px-6 py-3 text-right font-medium">Avg Cost</th>
                                <th className="px-6 py-3 text-right font-medium">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No stock balances recorded.
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
                                                {bal.quantity_on_hand} {bal.inventory_item?.unit}
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
                open={dialog === 'adjust'}
                onOpenChange={(next) => (next ? openDialog('adjust') : closeDialog())}
                title="Adjust Stock"
                description="Set the new on-hand quantity for an item at a location."
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
                    />
                    <div className="space-y-2">
                        <Label htmlFor="adjust-qty">New quantity on hand</Label>
                        <Input
                            id="adjust-qty"
                            type="number"
                            step="0.0001"
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
                            required
                        />
                        {adjustForm.errors.reason && (
                            <p className="text-sm text-red-600">{adjustForm.errors.reason}</p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={adjustForm.processing}
                        submitLabel="Save Adjustment"
                        processingLabel="Saving…"
                    />
                </form>
            </Dialog>

            <Dialog
                open={dialog === 'transfer'}
                onOpenChange={(next) => (next ? openDialog('transfer') : closeDialog())}
                title="Transfer Stock"
                description="Move quantity from one location to another."
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
                    />
                    <LocationSelect
                        id="transfer-to"
                        label="To location"
                        value={transferForm.data.to_location_id}
                        locations={stock_locations}
                        onChange={(v) => transferForm.setData('to_location_id', v)}
                        error={transferForm.errors.to_location_id}
                    />
                    <div className="space-y-2">
                        <Label htmlFor="transfer-qty">Quantity</Label>
                        <Input
                            id="transfer-qty"
                            type="number"
                            step="0.0001"
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
}: {
    id: string;
    label: string;
    value: string;
    locations: StockLocation[];
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                required
            >
                <option value="">Select location</option>
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
