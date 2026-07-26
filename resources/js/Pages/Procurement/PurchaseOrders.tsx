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
import { formatCurrency } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, PurchaseOrder, Requisition, Supplier } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface PurchaseOrdersProps extends PageProps {
    purchase_orders: Paginated<PurchaseOrder>;
    filters: ListingFilters;
    requisitions: Requisition[];
    suppliers: Supplier[];
}

export default function PurchaseOrders() {
    const { purchase_orders, filters, requisitions, suppliers } = usePage<PurchaseOrdersProps>().props;
    const rows = purchase_orders.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        requisition_id: '',
        supplier_id: '',
        quantity: '',
        unit_cost: '',
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
        post('/procurement/purchase-orders', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Purchase Orders">
            <Head title="Purchase Orders" />
            <div className="space-y-6">
                <PageHeader
                    title="Purchase Orders"
                    description="Create POs from approved requisitions."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Create Purchase Order
                        </Button>
                    }
                />

                <ListToolbar
                    baseUrl="/procurement/purchase-orders"
                    filters={filters}
                    searchPlaceholder="Search PO, supplier, requisition…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date created' },
                        { value: 'status', label: 'Status' },
                        { value: 'total_amount', label: 'Total amount' },
                    ]}
                />

                <DataPanel title="Purchase Orders" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">PO #</th>
                                <th className="px-6 py-3 font-medium">Supplier</th>
                                <th className="px-6 py-3 font-medium">Requisition</th>
                                <th className="px-6 py-3 text-right font-medium">Total</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No purchase orders found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((po) => (
                                    <tr key={po.id}>
                                        <td className="px-6 py-4 font-mono">PO-{po.id}</td>
                                        <td className="px-6 py-4">{po.supplier?.name}</td>
                                        <td className="px-6 py-4">
                                            {po.requisition?.requisition_no ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(po.total_amount)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={po.status} />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={purchase_orders} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Create Purchase Order"
                description="Create a PO from an approved requisition."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="po-requisition">Requisition</Label>
                        <select
                            id="po-requisition"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.requisition_id}
                            onChange={(e) => setData('requisition_id', e.target.value)}
                            required
                        >
                            <option value="">Select requisition</option>
                            {requisitions.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.requisition_no} — {formatCurrency(r.original_amount)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="po-supplier">Supplier</Label>
                        <select
                            id="po-supplier"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.supplier_id}
                            onChange={(e) => setData('supplier_id', e.target.value)}
                            required
                        >
                            <option value="">Select supplier</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="po-quantity">Quantity</Label>
                        <Input
                            id="po-quantity"
                            type="number"
                            step="0.0001"
                            value={data.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="po-unit-cost">Unit Cost</Label>
                        <Input
                            id="po-unit-cost"
                            type="number"
                            step="0.01"
                            value={data.unit_cost}
                            onChange={(e) => setData('unit_cost', e.target.value)}
                            required
                        />
                        {errors.unit_cost && (
                            <p className="text-sm text-red-600">{errors.unit_cost}</p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Create PO"
                        processingLabel="Creating…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
