import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatDate, formatQuantity } from '@/lib/formatters';
import { GoodsReceipt, ListingFilters, PageProps, Paginated, PurchaseOrder } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface GoodsReceiptsProps extends PageProps {
    goods_receipts: Paginated<GoodsReceipt>;
    filters: ListingFilters;
    purchase_orders: PurchaseOrder[];
}

export default function GoodsReceipts() {
    const { goods_receipts, filters, purchase_orders } = usePage<GoodsReceiptsProps>().props;
    const rows = goods_receipts.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        purchase_order_id: '',
        quantity_received: '',
        condition: 'good' as 'good' | 'damaged' | 'partial',
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
        post('/procurement/goods-receipts', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Goods Receipts">
            <Head title="Goods Receipts" />
            <div className="space-y-6">
                <PageHeader
                    title="Goods Receipts"
                    description="Record GRNs and update BOQ received quantities."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Record GRN
                        </Button>
                    }
                />

                <ListToolbar
                    baseUrl="/procurement/goods-receipts"
                    filters={filters}
                    searchPlaceholder="Search supplier…"
                    sortOptions={[
                        { value: 'received_at', label: 'Received date' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Goods Receipts" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">GRN #</th>
                                <th className="px-6 py-3 font-medium">PO</th>
                                <th className="px-6 py-3 font-medium">Qty</th>
                                <th className="px-6 py-3 font-medium">Condition</th>
                                <th className="px-6 py-3 font-medium">Received</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No goods receipts found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((grn) => (
                                    <tr key={grn.id}>
                                        <td className="px-6 py-4 font-mono">GRN-{grn.id}</td>
                                        <td className="px-6 py-4">PO-{grn.purchase_order_id}</td>
                                        <td className="px-6 py-4">{formatQuantity(grn.quantity_received)}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={grn.condition} />
                                        </td>
                                        <td className="px-6 py-4">{formatDate(grn.received_at)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={goods_receipts} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Record Goods Receipt"
                description="Record a GRN and update BOQ received quantities."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="grn-po">Purchase Order</Label>
                        <select
                            id="grn-po"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.purchase_order_id}
                            onChange={(e) => setData('purchase_order_id', e.target.value)}
                            required
                        >
                            <option value="">Select PO</option>
                            {purchase_orders.map((po) => (
                                <option key={po.id} value={po.id}>
                                    PO-{po.id} — {po.supplier?.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="grn-qty">Quantity Received</Label>
                        <Input
                            id="grn-qty"
                            type="number"
                            step="0.001"
                            value={data.quantity_received}
                            onChange={(e) => setData('quantity_received', e.target.value)}
                            required
                        />
                        {errors.quantity_received && (
                            <p className="text-sm text-red-600">{errors.quantity_received}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="grn-condition">Condition</Label>
                        <select
                            id="grn-condition"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.condition}
                            onChange={(e) =>
                                setData('condition', e.target.value as typeof data.condition)
                            }
                        >
                            <option value="good">Good</option>
                            <option value="damaged">Damaged</option>
                            <option value="partial">Partial</option>
                        </select>
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Record GRN"
                        processingLabel="Recording…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
