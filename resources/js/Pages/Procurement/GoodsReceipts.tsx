import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatDate } from '@/lib/formatters';
import { GoodsReceipt, ListingFilters, PageProps, Paginated, PurchaseOrder } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface GoodsReceiptsProps extends PageProps {
    goods_receipts: Paginated<GoodsReceipt>;
    filters: ListingFilters;
    purchase_orders: PurchaseOrder[];
}

export default function GoodsReceipts() {
    const { goods_receipts, filters, purchase_orders } = usePage<GoodsReceiptsProps>().props;
    const rows = goods_receipts.data ?? [];
    const { data, setData, post, processing, errors, reset } = useForm({
        purchase_order_id: '',
        quantity_received: '',
        condition: 'good' as 'good' | 'damaged' | 'partial',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/procurement/goods-receipts', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Goods Receipts">
            <Head title="Goods Receipts" />
            <div className="space-y-6">
                <PageHeader
                    title="Goods Receipts"
                    description="Record GRNs and update BOQ received quantities."
                />

                <DataPanel title="Record GRN">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-2">
                            <Label>Purchase Order</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
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
                            <Label>Quantity Received</Label>
                            <Input
                                type="number"
                                step="0.0001"
                                value={data.quantity_received}
                                onChange={(e) => setData('quantity_received', e.target.value)}
                                required
                            />
                            {errors.quantity_received && (
                                <p className="text-sm text-red-600">{errors.quantity_received}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Condition</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
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
                        <div>
                            <Button type="submit" disabled={processing}>
                                Record GRN
                            </Button>
                        </div>
                    </form>
                </DataPanel>

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
                                        <td className="px-6 py-4">{grn.quantity_received}</td>
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
        </AppShell>
    );
}
