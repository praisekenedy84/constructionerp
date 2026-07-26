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
import { formatCurrency, formatDate } from '@/lib/formatters';
import {
    InventoryIssue,
    InventoryItem,
    ListingFilters,
    PageProps,
    Paginated,
    StockLocation,
    User,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface IssuesProps extends PageProps {
    issues: Paginated<InventoryIssue>;
    filters: ListingFilters;
    inventory_items: InventoryItem[];
    stock_locations: StockLocation[];
    recipients: Pick<User, 'id' | 'name' | 'email'>[];
}

export default function Issues() {
    const { issues, filters, inventory_items, stock_locations, recipients } =
        usePage<IssuesProps>().props;
    const rows = issues.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty, transform } = useForm({
        inventory_item_id: '',
        stock_location_id: '',
        quantity: '',
        recipient_id: '',
        requisition_id: '',
        work_section: '',
    });

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
        transform((form) => ({
            ...form,
            requisition_id: form.requisition_id ? Number(form.requisition_id) : null,
        }));
        post('/inventory/issue', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Inventory Issues">
            <Head title="Inventory Issues" />
            <div className="space-y-6">
                <PageHeader
                    title="Inventory Issues"
                    description="Stock issued against requisitions."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Issue Stock
                        </Button>
                    }
                />

                <ListToolbar
                    baseUrl="/inventory/issues"
                    filters={filters}
                    searchPlaceholder="Search item, requisition, location…"
                    sortOptions={[
                        { value: 'issued_at', label: 'Issued date' },
                        { value: 'quantity', label: 'Quantity' },
                        { value: 'value', label: 'Value' },
                    ]}
                />

                <DataPanel title="Issue History" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Item</th>
                                <th className="px-6 py-3 font-medium">Requisition</th>
                                <th className="px-6 py-3 font-medium">Qty</th>
                                <th className="px-6 py-3 text-right font-medium">Value</th>
                                <th className="px-6 py-3 font-medium">Issued</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No issues found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((issue) => (
                                    <tr key={issue.id}>
                                        <td className="px-6 py-4">{issue.inventory_item?.name}</td>
                                        <td className="px-6 py-4 font-mono">
                                            {issue.requisition?.requisition_no ??
                                                (issue.requisition_id
                                                    ? `#${issue.requisition_id}`
                                                    : '—')}
                                        </td>
                                        <td className="px-6 py-4">{issue.quantity}</td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(issue.value)}
                                        </td>
                                        <td className="px-6 py-4">{formatDate(issue.issued_at)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={issues} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Issue Stock"
                description="Issue inventory from a stock location to a recipient."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="issue-item">Item</Label>
                        <select
                            id="issue-item"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.inventory_item_id}
                            onChange={(e) => setData('inventory_item_id', e.target.value)}
                            required
                        >
                            <option value="">Select item</option>
                            {inventory_items.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} — {item.name}
                                </option>
                            ))}
                        </select>
                        {errors.inventory_item_id && (
                            <p className="text-sm text-red-600">{errors.inventory_item_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="issue-location">Location</Label>
                        <select
                            id="issue-location"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.stock_location_id}
                            onChange={(e) => setData('stock_location_id', e.target.value)}
                            required
                        >
                            <option value="">Select location</option>
                            {stock_locations.map((loc) => (
                                <option key={loc.id} value={loc.id}>
                                    {loc.name}
                                </option>
                            ))}
                        </select>
                        {errors.stock_location_id && (
                            <p className="text-sm text-red-600">{errors.stock_location_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="issue-qty">Quantity</Label>
                        <Input
                            id="issue-qty"
                            type="number"
                            step="0.0001"
                            min="0"
                            value={data.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                            required
                        />
                        {errors.quantity && <p className="text-sm text-red-600">{errors.quantity}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="issue-recipient">Recipient</Label>
                        <select
                            id="issue-recipient"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.recipient_id}
                            onChange={(e) => setData('recipient_id', e.target.value)}
                            required
                        >
                            <option value="">Select recipient</option>
                            {recipients.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                        {errors.recipient_id && (
                            <p className="text-sm text-red-600">{errors.recipient_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="issue-req">Requisition ID (optional)</Label>
                        <Input
                            id="issue-req"
                            type="number"
                            value={data.requisition_id}
                            onChange={(e) => setData('requisition_id', e.target.value)}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="issue-section">Work section (optional)</Label>
                        <Input
                            id="issue-section"
                            value={data.work_section}
                            onChange={(e) => setData('work_section', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Issue Stock"
                        processingLabel="Issuing…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
