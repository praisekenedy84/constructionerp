import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import {
    confirmDiscardIfDirty,
    DialogFormActions,
    FormErrorSummary,
} from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import {
    Equipment,
    ListingFilters,
    PageProps,
    Paginated,
    PurchaseOrder,
    PurchaseOrderStatus,
    Requisition,
    Supplier,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CircleDollarSign, HandCoins, Landmark, Plus, ReceiptText, Trash2, Truck } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

interface FundedRequisition extends Requisition {
    allocated_amount: string;
    utilized_amount: string;
    available_balance: string;
}

interface PurchaseOrderForm {
    requisition_id: string;
    supplier_id: string;
    equipment_id: string;
    purchase_date: string;
    payment_amount: string;
    payment_method: '' | 'cash' | 'mobile' | 'bank';
    payment_reference_no: string;
    items: Array<{
        name: string;
        quantity: string;
        unit_price: string;
    }>;
}

interface PurchaseOrdersProps extends PageProps {
    purchase_orders: Paginated<PurchaseOrder>;
    summary: {
        order_count: number;
        total_amount: string;
        paid_amount: string;
        outstanding_amount: string;
    };
    filters: ListingFilters & {
        status?: string;
        payment_status?: string;
        supplier_id?: string;
        requisition_id?: string;
        equipment_id?: string;
    };
    filter_options: {
        requisitions: Array<Pick<Requisition, 'id' | 'requisition_no'>>;
        equipment: Array<Pick<Equipment, 'id' | 'name'>>;
    };
    requisitions: FundedRequisition[];
    suppliers: Supplier[];
    equipment: Equipment[];
}

const emptyItem = () => ({ name: '', quantity: '1', unit_price: '' });
const purchaseOrderStatuses: PurchaseOrderStatus[] = [
    'draft',
    'sent',
    'confirmed',
    'partially_received',
    'fully_received',
    'cancelled',
];

export default function PurchaseOrders() {
    const {
        purchase_orders,
        summary,
        filters,
        filter_options,
        requisitions,
        suppliers,
        equipment,
    } = usePage<PurchaseOrdersProps>().props;
    const rows = purchase_orders.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } =
        useForm<PurchaseOrderForm>({
            requisition_id: '',
            supplier_id: '',
            equipment_id: '',
            purchase_date: new Date().toISOString().split('T')[0],
            payment_amount: '',
            payment_method: '',
            payment_reference_no: '',
            items: [emptyItem()],
        });
    const [payable, setPayable] = useState<PurchaseOrder | null>(null);
    const paymentForm = useForm({
        amount: '',
        method: '' as '' | 'cash' | 'mobile' | 'bank',
        reference_no: '',
        notes: '',
        paid_at: new Date().toISOString().split('T')[0],
    });

    const selectedRequisition = requisitions.find(
        (requisition) => String(requisition.id) === data.requisition_id,
    );
    const purchaseTotal = useMemo(
        () =>
            data.items.reduce(
                (total, item) =>
                    total + (Number(item.quantity) || 0) * (Number(item.unit_price) || 0),
                0,
            ),
        [data.items],
    );
    const exceedsBalance =
        selectedRequisition !== undefined &&
        purchaseTotal > Number(selectedRequisition.available_balance);
    const paymentAmount = Number(data.payment_amount) || 0;
    const paymentExceedsTotal = paymentAmount > purchaseTotal;
    const supplierDebt = Math.max(purchaseTotal - paymentAmount, 0);

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

    function updateItem(index: number, field: keyof PurchaseOrderForm['items'][number], value: string) {
        setData(
            'items',
            data.items.map((item, itemIndex) =>
                itemIndex === index ? { ...item, [field]: value } : item,
            ),
        );
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

    function openPaymentDialog(purchaseOrder: PurchaseOrder) {
        paymentForm.clearErrors();
        paymentForm.setData({
            amount: purchaseOrder.outstanding_amount,
            method: '',
            reference_no: '',
            notes: '',
            paid_at: new Date().toISOString().split('T')[0],
        });
        setPayable(purchaseOrder);
    }

    function closePaymentDialog() {
        if (!confirmDiscardIfDirty(paymentForm.isDirty)) {
            return;
        }
        setPayable(null);
        paymentForm.reset();
        paymentForm.clearErrors();
    }

    function submitPayment(e: FormEvent) {
        e.preventDefault();
        if (!payable) {
            return;
        }

        paymentForm.post(`/procurement/purchase-orders/${payable.id}/payments`, {
            onSuccess: () => {
                paymentForm.reset();
                setPayable(null);
            },
        });
    }

    return (
        <AppShell title="Purchase Orders">
            <Head title="Purchase Orders" />
            <div className="space-y-6">
                <PageHeader
                    title="Purchase Orders"
                    description="Allocate approved requisition funds and review supplier purchases."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            New Purchase Order
                        </Button>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Orders Displayed
                                </p>
                                <p className="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                                    {summary.order_count}
                                </p>
                                <p className="mt-1 text-sm text-slate-500">
                                    Matching current filters
                                </p>
                            </div>
                            <ReceiptText className="h-5 w-5 text-slate-400" />
                        </div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Purchase Total
                                </p>
                                <p className="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                                    {formatCurrency(summary.total_amount)}
                                </p>
                                <p className="mt-1 text-sm text-slate-500">Filtered order value</p>
                            </div>
                            <CircleDollarSign className="h-5 w-5 text-slate-400" />
                        </div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Amount Paid
                                </p>
                                <p className="mt-1 text-2xl font-semibold text-emerald-700 dark:text-emerald-400">
                                    {formatCurrency(summary.paid_amount)}
                                </p>
                                <p className="mt-1 text-sm text-slate-500">Paid to suppliers</p>
                            </div>
                            <Landmark className="h-5 w-5 text-emerald-500" />
                        </div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Supplier Debt
                                </p>
                                <p className="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">
                                    {formatCurrency(summary.outstanding_amount)}
                                </p>
                                <p className="mt-1 text-sm text-slate-500">Outstanding balance</p>
                            </div>
                            <HandCoins className="h-5 w-5 text-red-500" />
                        </div>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/procurement/purchase-orders"
                    filters={filters}
                    searchPlaceholder="Search PO, supplier, requisition…"
                    sortOptions={[
                        { value: 'purchase_date', label: 'Purchase date' },
                        { value: 'created_at', label: 'Date created' },
                        { value: 'status', label: 'Status' },
                        { value: 'total_amount', label: 'Total amount' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Order status',
                            emptyLabel: 'All order statuses',
                            options: purchaseOrderStatuses.map((status) => ({
                                value: status,
                                label: status.replace(/_/g, ' '),
                            })),
                        },
                        {
                            key: 'payment_status',
                            label: 'Payment status',
                            emptyLabel: 'All payment statuses',
                            options: [
                                { value: 'unpaid', label: 'Unpaid' },
                                { value: 'partially_paid', label: 'Partially paid' },
                                { value: 'paid', label: 'Paid' },
                            ],
                        },
                        {
                            key: 'supplier_id',
                            label: 'Supplier',
                            emptyLabel: 'All suppliers',
                            options: suppliers.map((supplier) => ({
                                value: String(supplier.id),
                                label: supplier.name,
                            })),
                        },
                        {
                            key: 'requisition_id',
                            label: 'Requisition',
                            emptyLabel: 'All requisitions',
                            options: filter_options.requisitions.map((requisition) => ({
                                value: String(requisition.id),
                                label: requisition.requisition_no,
                            })),
                        },
                        {
                            key: 'equipment_id',
                            label: 'Vehicle / equipment',
                            emptyLabel: 'All vehicles / equipment',
                            options: filter_options.equipment.map((item) => ({
                                value: String(item.id),
                                label: item.name,
                            })),
                        },
                    ]}
                />

                <DataPanel title="Purchase Orders" noPadding>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/60">
                                    <th className="px-6 py-3 font-medium">PO Number</th>
                                    <th className="px-6 py-3 font-medium">Date</th>
                                    <th className="px-6 py-3 font-medium">Supplier</th>
                                    <th className="px-6 py-3 font-medium">Requisition</th>
                                    <th className="px-6 py-3 font-medium">Order Status</th>
                                    <th className="px-6 py-3 font-medium">Vehicle</th>
                                    <th className="px-6 py-3 text-right font-medium">Total</th>
                                    <th className="px-6 py-3 text-right font-medium">Paid</th>
                                    <th className="px-6 py-3 text-right font-medium">Supplier Debt</th>
                                    <th className="px-6 py-3 font-medium">Debt Status</th>
                                    <th className="px-6 py-3 text-right font-medium">Payment</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={11} className="px-6 py-12 text-center text-slate-500">
                                            No purchase orders match the current filters.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((po) => (
                                        <tr key={po.id}>
                                            <td className="px-6 py-4 font-mono font-medium">
                                                {po.purchase_order_no ?? `PO-${po.id}`}
                                            </td>
                                            <td className="px-6 py-4">
                                                {po.purchase_date ? formatDate(po.purchase_date) : '—'}
                                            </td>
                                            <td className="px-6 py-4">{po.supplier?.name}</td>
                                            <td className="px-6 py-4">
                                                {po.requisition?.requisition_no ?? '—'}
                                            </td>
                                            <td className="px-6 py-4">
                                                <StatusBadge status={po.status} />
                                            </td>
                                            <td className="px-6 py-4">{po.equipment?.name ?? '—'}</td>
                                            <td className="px-6 py-4 text-right font-medium">
                                                {formatCurrency(po.total_amount)}
                                            </td>
                                            <td className="px-6 py-4 text-right text-emerald-700 dark:text-emerald-400">
                                                {formatCurrency(po.paid_amount)}
                                            </td>
                                            <td className="px-6 py-4 text-right font-semibold text-red-600 dark:text-red-400">
                                                {formatCurrency(po.outstanding_amount)}
                                            </td>
                                            <td className="px-6 py-4">
                                                <StatusBadge status={po.payment_status} />
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                {Number(po.outstanding_amount) > 0 ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => openPaymentDialog(po)}
                                                    >
                                                        <HandCoins className="h-4 w-4" />
                                                        Pay Debt
                                                    </Button>
                                                ) : (
                                                    <StatusBadge status="paid" />
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <PaginationLinks paginator={purchase_orders} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="New Purchase Order"
                description="Select a funded requisition and add the supplier purchase items."
                className="max-w-5xl"
            >
                <form onSubmit={submit} className="space-y-5">
                    <FormErrorSummary
                        errors={errors as Record<string, string | undefined>}
                        handled={[
                            'requisition_id',
                            'supplier_id',
                            'items',
                            'payment_amount',
                            'payment_method',
                            'payment_reference_no',
                        ]}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="po-requisition">Approved Requisition</Label>
                            <select
                                id="po-requisition"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                value={data.requisition_id}
                                onChange={(e) => setData('requisition_id', e.target.value)}
                                required
                            >
                                <option value="">Select requisition</option>
                                {requisitions.map((requisition) => (
                                    <option key={requisition.id} value={requisition.id}>
                                        {requisition.requisition_no} — available{' '}
                                        {formatCurrency(requisition.available_balance)}
                                    </option>
                                ))}
                            </select>
                            {errors.requisition_id && (
                                <p className="text-sm text-red-600">{errors.requisition_id}</p>
                            )}
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
                                {suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>
                                        {supplier.name}
                                    </option>
                                ))}
                            </select>
                            {errors.supplier_id && (
                                <p className="text-sm text-red-600">{errors.supplier_id}</p>
                            )}
                        </div>
                    </div>

                    {selectedRequisition && (
                        <div className="grid grid-cols-3 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                            <div>
                                <p className="text-xs text-slate-500">Allocated</p>
                                <p className="mt-1 text-sm font-semibold">
                                    {formatCurrency(selectedRequisition.allocated_amount)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Utilized</p>
                                <p className="mt-1 text-sm font-semibold">
                                    {formatCurrency(selectedRequisition.utilized_amount)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Available</p>
                                <p className="mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    {formatCurrency(selectedRequisition.available_balance)}
                                </p>
                            </div>
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="po-date">Purchase Date</Label>
                            <Input
                                id="po-date"
                                type="date"
                                value={data.purchase_date}
                                onChange={(e) => setData('purchase_date', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="po-equipment">
                                <span className="inline-flex items-center gap-1.5">
                                    <Truck className="h-4 w-4" />
                                    Vehicle / Equipment (optional)
                                </span>
                            </Label>
                            <select
                                id="po-equipment"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                value={data.equipment_id}
                                onChange={(e) => setData('equipment_id', e.target.value)}
                            >
                                <option value="">Not vehicle related</option>
                                {equipment.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name} — {item.type}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <Label>Purchase Items</Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setData('items', [...data.items, emptyItem()])}
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add Item
                            </Button>
                        </div>
                        {data.items.map((item, index) => {
                            const lineTotal =
                                (Number(item.quantity) || 0) * (Number(item.unit_price) || 0);
                            return (
                                <div
                                    key={index}
                                    className="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,2fr)_0.7fr_1fr_1fr_auto] dark:border-slate-700"
                                >
                                    <div className="space-y-1">
                                        <Label htmlFor={`po-item-${index}`} className="text-xs">
                                            Product / Item
                                        </Label>
                                        <Input
                                            id={`po-item-${index}`}
                                            value={item.name}
                                            onChange={(e) => updateItem(index, 'name', e.target.value)}
                                            placeholder="e.g. Engine oil"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor={`po-qty-${index}`} className="text-xs">
                                            Quantity
                                        </Label>
                                        <Input
                                            id={`po-qty-${index}`}
                                            type="number"
                                            min="0.001"
                                            step="0.001"
                                            value={item.quantity}
                                            onChange={(e) => updateItem(index, 'quantity', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor={`po-price-${index}`} className="text-xs">
                                            Unit Price
                                        </Label>
                                        <AmountInput
                                            id={`po-price-${index}`}
                                            value={item.unit_price}
                                            onValueChange={(value) => updateItem(index, 'unit_price', value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <span className="block text-xs font-medium">Total</span>
                                        <div className="flex h-10 items-center justify-end font-semibold">
                                            {formatCurrency(lineTotal)}
                                        </div>
                                    </div>
                                    <div className="flex items-end">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="h-10 w-10 px-0"
                                            disabled={data.items.length === 1}
                                            onClick={() =>
                                                setData(
                                                    'items',
                                                    data.items.filter((_, itemIndex) => itemIndex !== index),
                                                )
                                            }
                                            aria-label="Remove item"
                                        >
                                            <Trash2 className="h-4 w-4 text-red-500" />
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                        {errors.items && <p className="text-sm text-red-600">{errors.items}</p>}
                    </div>

                    <div className="space-y-3 rounded-lg border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/20">
                        <div>
                            <p className="font-medium">Supplier Payment</p>
                            <p className="text-xs text-slate-500">
                                Record the amount paid now through this requisition. Any unpaid balance
                                becomes a debt owed to the selected supplier.
                            </p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label htmlFor="po-payment-amount">Amount Paid Now</Label>
                                <AmountInput
                                    id="po-payment-amount"
                                    value={data.payment_amount}
                                    onValueChange={(value) => setData('payment_amount', value)}
                                    placeholder="0"
                                />
                                {errors.payment_amount && (
                                    <p className="text-xs text-red-600">{errors.payment_amount}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="po-payment-method">Payment Method</Label>
                                <select
                                    id="po-payment-method"
                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                    value={data.payment_method}
                                    onChange={(e) =>
                                        setData(
                                            'payment_method',
                                            e.target.value as PurchaseOrderForm['payment_method'],
                                        )
                                    }
                                    required={paymentAmount > 0}
                                >
                                    <option value="">Select method</option>
                                    <option value="cash">Cash</option>
                                    <option value="mobile">Mobile Money</option>
                                    <option value="bank">Bank</option>
                                </select>
                                {errors.payment_method && (
                                    <p className="text-xs text-red-600">{errors.payment_method}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="po-payment-reference">Payment Reference</Label>
                                <Input
                                    id="po-payment-reference"
                                    value={data.payment_reference_no}
                                    onChange={(e) =>
                                        setData('payment_reference_no', e.target.value)
                                    }
                                    placeholder="Receipt / transaction no."
                                    required={paymentAmount > 0}
                                />
                                {errors.payment_reference_no && (
                                    <p className="text-xs text-red-600">
                                        {errors.payment_reference_no}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div
                        className={`flex items-center justify-between rounded-lg border p-4 ${
                            exceedsBalance || paymentExceedsTotal
                                ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/30'
                                : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/50'
                        }`}
                    >
                        <div>
                            <p className="text-sm text-slate-500">Purchase total</p>
                            {exceedsBalance && (
                                <p className="text-xs font-medium text-red-600">
                                    This exceeds the requisition balance.
                                </p>
                            )}
                            {paymentExceedsTotal && (
                                <p className="text-xs font-medium text-red-600">
                                    Payment cannot exceed the purchase total.
                                </p>
                            )}
                        </div>
                        <div className="grid grid-cols-3 gap-6 text-right">
                            <div>
                                <p className="text-xs text-slate-500">Total</p>
                                <p className="font-bold">{formatCurrency(purchaseTotal)}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Paid</p>
                                <p className="font-bold text-emerald-700 dark:text-emerald-400">
                                    {formatCurrency(paymentAmount)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Supplier Debt</p>
                                <p className="font-bold text-red-600 dark:text-red-400">
                                    {formatCurrency(supplierDebt)}
                                </p>
                            </div>
                        </div>
                    </div>

                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Create Purchase Order"
                        processingLabel="Creating…"
                        disabled={exceedsBalance || paymentExceedsTotal || purchaseTotal <= 0}
                    />
                </form>
            </Dialog>

            <Dialog
                open={payable !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        closePaymentDialog();
                    }
                }}
                title={`Pay Supplier Debt${payable ? ` — ${payable.purchase_order_no ?? `PO-${payable.id}`}` : ''}`}
                description={
                    payable
                        ? `${payable.supplier?.name ?? 'Supplier'} is owed ${formatCurrency(payable.outstanding_amount)} (${payable.payment_status.replace(/_/g, ' ')}).`
                        : undefined
                }
            >
                <form onSubmit={submitPayment} className="space-y-4">
                    <FormErrorSummary
                        errors={paymentForm.errors as Record<string, string | undefined>}
                        handled={['amount', 'method', 'reference_no']}
                    />
                    <div className="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-4 dark:border-slate-700 dark:bg-slate-800/50">
                        <div>
                            <p className="text-xs text-slate-500">PO Total</p>
                            <p className="font-semibold">{formatCurrency(payable?.total_amount)}</p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-500">Already Paid</p>
                            <p className="font-semibold text-emerald-700 dark:text-emerald-400">
                                {formatCurrency(payable?.paid_amount)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-500">Debt</p>
                            <p className="font-semibold text-red-600 dark:text-red-400">
                                {formatCurrency(payable?.outstanding_amount)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-500">Debt Status</p>
                            <div className="mt-1">
                                {payable && <StatusBadge status={payable.payment_status} />}
                            </div>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="debt-payment-amount">Payment Amount</Label>
                        <AmountInput
                            id="debt-payment-amount"
                            value={paymentForm.data.amount}
                            onValueChange={(value) => paymentForm.setData('amount', value)}
                            required
                        />
                        {paymentForm.errors.amount && (
                            <p className="text-sm text-red-600">{paymentForm.errors.amount}</p>
                        )}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="debt-payment-method">Payment Method</Label>
                            <select
                                id="debt-payment-method"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                value={paymentForm.data.method}
                                onChange={(e) =>
                                    paymentForm.setData(
                                        'method',
                                        e.target.value as typeof paymentForm.data.method,
                                    )
                                }
                                required
                            >
                                <option value="">Select method</option>
                                <option value="cash">Cash</option>
                                <option value="mobile">Mobile Money</option>
                                <option value="bank">Bank</option>
                            </select>
                            {paymentForm.errors.method && (
                                <p className="text-sm text-red-600">{paymentForm.errors.method}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="debt-payment-reference">Payment Reference</Label>
                            <Input
                                id="debt-payment-reference"
                                value={paymentForm.data.reference_no}
                                onChange={(e) =>
                                    paymentForm.setData('reference_no', e.target.value)
                                }
                                required
                            />
                            {paymentForm.errors.reference_no && (
                                <p className="text-sm text-red-600">
                                    {paymentForm.errors.reference_no}
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="debt-payment-date">Payment Date</Label>
                        <Input
                            id="debt-payment-date"
                            type="date"
                            value={paymentForm.data.paid_at}
                            onChange={(e) => paymentForm.setData('paid_at', e.target.value)}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="debt-payment-notes">Notes (optional)</Label>
                        <Input
                            id="debt-payment-notes"
                            value={paymentForm.data.notes}
                            onChange={(e) => paymentForm.setData('notes', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closePaymentDialog}
                        processing={paymentForm.processing}
                        submitLabel="Record Supplier Payment"
                        processingLabel="Recording…"
                        disabled={
                            Number(paymentForm.data.amount) <= 0 ||
                            Number(paymentForm.data.amount) >
                                Number(payable?.outstanding_amount ?? 0)
                        }
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
