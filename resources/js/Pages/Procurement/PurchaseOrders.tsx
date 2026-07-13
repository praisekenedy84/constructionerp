import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, PurchaseOrder, Requisition, Supplier } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface PurchaseOrdersProps extends PageProps {
    purchase_orders: Paginated<PurchaseOrder>;
    filters: ListingFilters;
    requisitions: Requisition[];
    suppliers: Supplier[];
}

export default function PurchaseOrders() {
    const { purchase_orders, filters, requisitions, suppliers } = usePage<PurchaseOrdersProps>().props;
    const rows = purchase_orders.data ?? [];
    const { data, setData, post, processing, errors, reset } = useForm({
        requisition_id: '',
        supplier_id: '',
        quantity: '',
        unit_cost: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/procurement/purchase-orders', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Purchase Orders">
            <Head title="Purchase Orders" />
            <div className="space-y-6">
                <PageHeader
                    title="Purchase Orders"
                    description="Create POs from approved requisitions."
                />

                <DataPanel title="Create Purchase Order">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Requisition</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
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
                            <Label>Supplier</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
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
                            <Label>Quantity</Label>
                            <Input
                                type="number"
                                step="0.0001"
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Unit Cost</Label>
                            <Input
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
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={processing}>
                                Create PO
                            </Button>
                        </div>
                    </form>
                </DataPanel>

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
        </AppShell>
    );
}
