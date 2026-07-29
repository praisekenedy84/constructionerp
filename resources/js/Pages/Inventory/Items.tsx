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
import { InventoryItem, ListingFilters, PageProps, Paginated, StockLocation } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PackagePlus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface CategoryOption {
    value: string;
    label: string;
}

interface ItemsProps extends PageProps {
    items: Paginated<InventoryItem>;
    filters: ListingFilters;
    categories: CategoryOption[];
    stock_locations: StockLocation[];
}

function previewItemCode(name: string): string {
    const tokens = name.trim().split(/[^A-Za-z0-9]+/).filter(Boolean);
    const letters: string[] = [];
    const numbers: string[] = [];

    for (const token of tokens) {
        if (/\d/.test(token)) {
            const match = token.match(/(\d+)([A-Za-z]{0,3})/i);
            if (match) {
                numbers.push(`${match[1]}${match[2]}`.toUpperCase());
            }
            continue;
        }

        letters.push(token[0].toUpperCase());
    }

    if (letters.length === 0 && numbers.length === 0) {
        return 'ITEM';
    }

    if (letters.length === 1 && numbers.length === 0 && tokens[0]) {
        letters[0] = tokens[0].slice(0, 4).toUpperCase();
    }

    const prefix = letters.join('');
    const base = numbers.length === 0 ? prefix : `${prefix}-${numbers.join('-')}`;

    return base.slice(0, 16).replace(/-+$/g, '') || 'ITEM';
}

export default function Items() {
    const { items, filters, categories, stock_locations } = usePage<ItemsProps>().props;
    const rows = items.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        name: '',
        unit: '',
        category: categories[0]?.value ?? 'materials',
        reorder_point: '',
        opening_quantity: '',
        stock_location_id: '',
        unit_cost: '',
    });

    const wantsOpeningStock = Boolean(data.opening_quantity || data.stock_location_id);

    function openDialog() {
        clearErrors();
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        reset();
        clearErrors();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/inventory/items', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Items">
            <Head title="Inventory Items" />
            <div className="space-y-6">
                <PageHeader
                    title="1. Items"
                    description="Define what you stock. Optionally record opening quantity in the same step."
                    actions={
                        <Button onClick={openDialog}>
                            <PackagePlus className="mr-2 h-4 w-4" />
                            New Item
                        </Button>
                    }
                />
                <InventoryNav active="items" />

                <ListToolbar
                    baseUrl="/inventory/items"
                    filters={filters}
                    searchPlaceholder="Search code, name, unit…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'code', label: 'Code' },
                        { value: 'category', label: 'Category' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Item catalog" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <th className="px-6 py-3 font-medium">Code</th>
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Unit</th>
                                <th className="px-6 py-3 font-medium">Category</th>
                                <th className="px-6 py-3 font-medium">Reorder point</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No items yet. Create one, then add stock under{' '}
                                        <Link href="/inventory/balances" className="text-blue-700 underline">
                                            On Hand
                                        </Link>
                                        .
                                    </td>
                                </tr>
                            ) : (
                                rows.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-6 py-4 font-medium text-slate-900 dark:text-slate-100">
                                            {item.code}
                                        </td>
                                        <td className="px-6 py-4 text-slate-700 dark:text-slate-300">
                                            {item.name}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600 dark:text-slate-400">
                                            {item.unit}
                                        </td>
                                        <td className="px-6 py-4 capitalize text-slate-600 dark:text-slate-400">
                                            {item.category.replaceAll('_', ' ')}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600 dark:text-slate-400">
                                            {item.reorder_point ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={items} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="New item"
                description="Step 1 of stock flow. Add opening stock now, or leave it blank and receive later."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="item-name">Name</Label>
                        <Input
                            id="item-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Portland Cement 50kg"
                            required
                            autoFocus
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="item-code-preview">Code</Label>
                        <Input
                            id="item-code-preview"
                            value={previewItemCode(data.name)}
                            readOnly
                            className="bg-slate-50 font-mono text-slate-700 dark:bg-slate-900 dark:text-slate-300"
                        />
                        {errors.code && <p className="text-sm text-red-600">{errors.code}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="item-unit">Unit</Label>
                            <Input
                                id="item-unit"
                                value={data.unit}
                                onChange={(e) => setData('unit', e.target.value)}
                                placeholder="bag, pcs, liter…"
                                required
                            />
                            {errors.unit && <p className="text-sm text-red-600">{errors.unit}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="item-category">Category</Label>
                            <select
                                id="item-category"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                required
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
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="item-reorder">Reorder point (optional)</Label>
                        <Input
                            id="item-reorder"
                            type="number"
                            min="0"
                            step="any"
                            value={data.reorder_point}
                            onChange={(e) => setData('reorder_point', e.target.value)}
                            placeholder="100"
                        />
                        {errors.reorder_point && (
                            <p className="text-sm text-red-600">{errors.reorder_point}</p>
                        )}
                    </div>

                    <div className="space-y-3 rounded-md border border-slate-200 p-4 dark:border-slate-700">
                        <div>
                            <p className="text-sm font-medium text-slate-900 dark:text-slate-100">
                                Opening stock (optional)
                            </p>
                            <p className="text-xs text-slate-500">
                                Put quantity on the shelf now. Skip if stock arrives later.
                            </p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="item-opening-qty">Quantity</Label>
                                <Input
                                    id="item-opening-qty"
                                    type="number"
                                    min="0"
                                    step="any"
                                    value={data.opening_quantity}
                                    onChange={(e) => setData('opening_quantity', e.target.value)}
                                />
                                {errors.opening_quantity && (
                                    <p className="text-sm text-red-600">{errors.opening_quantity}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="item-opening-location">Location</Label>
                                <select
                                    id="item-opening-location"
                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                    value={data.stock_location_id}
                                    onChange={(e) => setData('stock_location_id', e.target.value)}
                                    required={wantsOpeningStock}
                                >
                                    <option value="">
                                        {stock_locations.length === 0
                                            ? 'No locations yet'
                                            : 'Select location'}
                                    </option>
                                    {stock_locations.map((loc) => (
                                        <option key={loc.id} value={loc.id}>
                                            {loc.name}
                                        </option>
                                    ))}
                                </select>
                                {stock_locations.length === 0 && (
                                    <p className="text-xs text-slate-500">
                                        Create one under{' '}
                                        <Link
                                            href="/inventory/balances"
                                            className="text-blue-700 underline"
                                        >
                                            On Hand → Add location
                                        </Link>
                                        .
                                    </p>
                                )}
                                {errors.stock_location_id && (
                                    <p className="text-sm text-red-600">{errors.stock_location_id}</p>
                                )}
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="item-unit-cost">Unit cost (optional)</Label>
                            <AmountInput
                                id="item-unit-cost"
                                min="0"
                                value={data.unit_cost}
                                onValueChange={(v) => setData('unit_cost', v)}
                                placeholder="0"
                            />
                            {errors.unit_cost && (
                                <p className="text-sm text-red-600">{errors.unit_cost}</p>
                            )}
                        </div>
                    </div>

                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel={wantsOpeningStock ? 'Create & stock' : 'Create item'}
                        processingLabel="Saving…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
