import AppShell from '@/Components/Layout/AppShell';
import AmendRequisitionForm from '@/Components/Domain/AmendRequisitionForm';
import RequisitionTimeline from '@/Components/Domain/RequisitionTimeline';
import DataPanel from '@/Components/Shared/DataPanel';
import { IconLink } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PaymentMethodSelect } from '@/Components/ui/payment-method-select';
import { formatCurrency, formatQuantity } from '@/lib/formatters';
import { canOverrideLimits, hasPermission } from '@/lib/permissions';
import {
    ApprovalStep,
    InventoryItem,
    PageProps,
    Requisition,
    StockLocation,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { ClipboardCheck, ExternalLink, Pencil } from 'lucide-react';

interface CategoryOption {
    value: string;
    label: string;
}

interface RequisitionsShowProps extends PageProps {
    requisition: Requisition;
    pendingStep: ApprovalStep | null;
    inventoryItems: InventoryItem[];
    stockLocations: StockLocation[];
    inventoryCategories: CategoryOption[];
    cashOnHand: string;
    canEdit?: boolean;
    canDecide?: boolean;
}

export default function RequisitionsShow() {
    const {
        requisition,
        auth,
        inventoryItems,
        stockLocations,
        inventoryCategories = [],
        cashOnHand,
        canEdit,
        canDecide,
        pendingStep,
    } = usePage<RequisitionsShowProps>().props;

    const transitionForm = useForm({
        to_status: '',
        comment: '',
        inventory_source: 'existing' as 'existing' | 'new',
        inventory_item_id: String(
            requisition.items?.find((item) => item.inventory_item_id)?.inventory_item_id ?? '',
        ),
        stock_location_id: '',
        method: 'cash',
        payee: '',
        account_name: '',
        reference_no: '',
        new_inventory_item: {
            name: '',
            unit: '',
            category: inventoryCategories[0]?.value ?? 'materials',
            code: '',
            unit_cost: '',
            receive_quantity: '',
        },
    });
    const attachmentForm = useForm<{ file: File | null; document_type: string }>({
        file: null,
        document_type: 'receipt',
    });
    const approveForm = useForm({ action: 'approved', comment: '', override: false });
    const rejectForm = useForm({ action: 'rejected', comment: '' });
    const showOverride = canOverrideLimits(auth.user);

    function transition(e: FormEvent, toStatus: string) {
        e.preventDefault();
        transitionForm.setData('to_status', toStatus);
        transitionForm.post(`/requisitions/${requisition.id}/transition`);
    }

    function uploadAttachment(e: FormEvent) {
        e.preventDefault();
        attachmentForm.post(`/requisitions/${requisition.id}/attachments`, {
            forceFormData: true,
        });
    }

    function submitResolve(
        form: ReturnType<typeof useForm>,
        e: FormEvent,
    ) {
        e.preventDefault();
        if (!pendingStep) {
            return;
        }
        form.post(`/approvals/steps/${pendingStep.id}/resolve`);
    }

    const amount = requisition.amended_amount ?? requisition.original_amount;
    const variance =
        requisition.amended_amount != null
            ? (parseFloat(String(requisition.original_amount)) || 0) -
              (parseFloat(String(requisition.amended_amount)) || 0)
            : null;
    const history =
        requisition.history ??
        (requisition as Requisition & { status_histories?: Requisition['history'] }).status_histories ??
        [];
    const fulfillmentLabel = String(requisition.fulfillment_type).replace(/_/g, ' ');
    const status = String(requisition.status);
    const isStockFulfillment =
        requisition.addressed_to === 'storekeeper' ||
        (!requisition.addressed_to && requisition.fulfillment_type === 'stock_issue');
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');
    const canFulfill = hasPermission(auth.user, 'requisitions', 'fulfill');
    const showEdit = Boolean(canEdit) && canUpdate;
    const hasLineAmendments = (requisition.items ?? []).some(
        (item) => item.original_quantity != null || item.original_line_total != null,
    );

    return (
        <AppShell title={requisition.requisition_no}>
            <Head title={requisition.requisition_no} />
            <div className="space-y-6">
                <PageHeader
                    title={requisition.requisition_no}
                    description={`Requested by ${requisition.requestor?.name ?? 'Unknown'} · ${requisition.department} · ${requisition.project?.name ?? ''}`}
                    actions={
                        <div className="flex items-center gap-2">
                            {canDecide && (
                                <Link href={`/requisitions/review-queue?requisition_id=${requisition.id}`}>
                                    <Button variant="outline">
                                        <ClipboardCheck className="h-4 w-4" />
                                        Decide
                                    </Button>
                                </Link>
                            )}
                            {showEdit && (
                                <Link href={`/requisitions/${requisition.id}/edit`}>
                                    <Button variant="outline">
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                            )}
                            <StatusBadge status={status} />
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-4">
                    <DataPanel title="Original Amount">
                        <p className="text-xl font-bold text-slate-900">
                            {formatCurrency(requisition.original_amount)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Amended Amount">
                        <p className="text-xl font-bold text-amber-700">
                            {requisition.amended_amount
                                ? formatCurrency(requisition.amended_amount)
                                : '—'}
                        </p>
                    </DataPanel>
                    <DataPanel title="Difference">
                        <p className="text-xl font-bold text-slate-900">
                            {variance == null
                                ? '—'
                                : `${variance > 0 ? '−' : variance < 0 ? '+' : ''}${formatCurrency(Math.abs(variance))}`}
                        </p>
                        {variance != null && variance !== 0 && (
                            <p className="mt-1 text-xs text-slate-500">
                                {variance > 0 ? 'Reduced vs original' : 'Increased vs original'}
                            </p>
                        )}
                    </DataPanel>
                    <DataPanel title="Cash on Hand">
                        <p className="text-sm font-medium text-slate-700">
                            {formatCurrency(cashOnHand)}
                        </p>
                        <p className="mt-1 text-xs capitalize text-slate-500">
                            To{' '}
                            {String(
                                requisition.addressed_to ??
                                    (isStockFulfillment ? 'storekeeper' : 'finance'),
                            )}{' '}
                            · {String(requisition.resource_type ?? '—').replace(/_/g, ' ')} ·{' '}
                            {fulfillmentLabel}
                        </p>
                    </DataPanel>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel title="Line Items">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-slate-500">
                                    <th className="pb-2 font-medium">Description</th>
                                    <th className="pb-2 font-medium">Unit</th>
                                    <th className="pb-2 text-right font-medium">Qty</th>
                                    <th className="pb-2 text-right font-medium">Unit Cost</th>
                                    <th className="pb-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(requisition.items ?? []).map((item) => {
                                    const changed =
                                        item.original_line_total != null &&
                                        String(item.original_line_total) !== String(item.line_total);
                                    return (
                                    <tr key={item.id}>
                                        <td className="py-2 text-slate-900">
                                            <div>{item.description}</div>
                                            {item.original_description &&
                                                item.original_description !== item.description && (
                                                    <div className="text-xs text-slate-400 line-through">
                                                        Was: {item.original_description}
                                                    </div>
                                                )}
                                            {item.details?.workers && (
                                                <div className="text-xs text-slate-500">
                                                    {item.details.workers} workers ×{' '}
                                                    {item.details.days} days @{' '}
                                                    {formatCurrency(item.details.rate_per_day ?? '0')}
                                                    /day
                                                </div>
                                            )}
                                            {item.details?.duration && (
                                                <div className="text-xs text-slate-500">
                                                    {item.details.duration}{' '}
                                                    {item.details.duration_unit}
                                                    (s) @ {formatCurrency(item.details.rate ?? '0')}
                                                </div>
                                            )}
                                            {item.details?.trips && (
                                                <div className="text-xs text-slate-500">
                                                    {item.details.trips} trips @{' '}
                                                    {formatCurrency(item.details.cost_per_trip ?? '0')}
                                                    /trip
                                                </div>
                                            )}
                                            {item.details?.estimated_amount && (
                                                <div className="text-xs text-slate-500">
                                                    Cash estimate:{' '}
                                                    {formatCurrency(item.details.estimated_amount)}
                                                </div>
                                            )}
                                            {item.inventory_item && (
                                                <div className="text-xs text-slate-500">
                                                    Catalog: {item.inventory_item.code}
                                                </div>
                                            )}
                                            {!item.inventory_item_id &&
                                                !item.details &&
                                                requisition.resource_type !== 'cash' && (
                                                    <div className="text-xs text-slate-400">
                                                        New item request
                                                    </div>
                                                )}
                                        </td>
                                        <td className="py-2 text-slate-600">{item.unit ?? '—'}</td>
                                        <td className="py-2 text-right text-slate-600">
                                            <div>{formatQuantity(item.quantity)}</div>
                                            {item.original_quantity != null &&
                                                String(item.original_quantity) !==
                                                    String(item.quantity) && (
                                                    <div className="text-xs text-slate-400 line-through">
                                                        {formatQuantity(item.original_quantity)}
                                                    </div>
                                                )}
                                        </td>
                                        <td className="py-2 text-right text-slate-600">
                                            <div>{formatCurrency(item.unit_cost)}</div>
                                            {item.original_unit_cost != null &&
                                                String(item.original_unit_cost) !==
                                                    String(item.unit_cost) && (
                                                    <div className="text-xs text-slate-400 line-through">
                                                        {formatCurrency(item.original_unit_cost)}
                                                    </div>
                                                )}
                                        </td>
                                        <td
                                            className={`py-2 text-right font-medium ${
                                                changed ? 'text-amber-800' : 'text-slate-900'
                                            }`}
                                        >
                                            <div>{formatCurrency(item.line_total)}</div>
                                            {item.original_line_total != null && changed && (
                                                <div className="text-xs font-normal text-slate-400 line-through">
                                                    {formatCurrency(item.original_line_total)}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={4} className="pt-3 text-right font-medium">
                                        {hasLineAmendments ? 'Amended total' : 'Total'}
                                    </td>
                                    <td className="pt-3 text-right font-bold text-slate-900">
                                        {formatCurrency(amount)}
                                    </td>
                                </tr>
                                {hasLineAmendments && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="pt-1 text-right text-xs text-slate-500"
                                        >
                                            Original total
                                        </td>
                                        <td className="pt-1 text-right text-xs text-slate-500 line-through">
                                            {formatCurrency(requisition.original_amount)}
                                        </td>
                                    </tr>
                                )}
                            </tfoot>
                        </table>
                    </DataPanel>

                    <DataPanel title="BOQ Impact">
                        {requisition.boq_item ? (
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-slate-500">BOQ Item</dt>
                                    <dd className="text-sm text-slate-900">
                                        {requisition.boq_item.description}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-slate-500">Available</dt>
                                    <dd className="text-sm font-medium text-green-700">
                                        {formatQuantity(requisition.boq_item.available_qty)}{' '}
                                        {requisition.boq_item.unit}
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            <p className="text-sm text-slate-500">
                                No BOQ item linked. Budget is tracked at project level only.
                            </p>
                        )}
                    </DataPanel>
                </div>

                <DataPanel title="Status Timeline">
                    <RequisitionTimeline history={history} />
                </DataPanel>

                {canUpdate && (
                    <DataPanel title="Attachments">
                        {(requisition.attachments ?? []).length > 0 ? (
                            <ul className="mb-4 divide-y divide-slate-100">
                                {requisition.attachments!.map((att) => (
                                    <li key={att.id} className="flex items-center justify-between py-2">
                                        <span className="text-sm text-slate-700 capitalize">
                                            {att.document_type}
                                        </span>
                                        <IconLink
                                            href={att.file_url}
                                            icon={ExternalLink}
                                            label="View attachment"
                                            external
                                        />
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mb-4 text-sm text-slate-500">No attachments yet.</p>
                        )}
                        <form onSubmit={uploadAttachment} className="flex flex-wrap items-end gap-3">
                            <input
                                type="file"
                                onChange={(e) =>
                                    attachmentForm.setData('file', e.target.files?.[0] ?? null)
                                }
                                className="text-sm"
                            />
                            <select
                                className="h-10 rounded-md border border-slate-200 px-3 text-sm"
                                value={attachmentForm.data.document_type}
                                onChange={(e) =>
                                    attachmentForm.setData('document_type', e.target.value)
                                }
                            >
                                <option value="quotation">Quotation</option>
                                <option value="grn">GRN</option>
                                <option value="receipt">Receipt</option>
                                <option value="invoice">Invoice</option>
                                <option value="other">Other</option>
                            </select>
                            <Button type="submit" size="sm" disabled={attachmentForm.processing}>
                                Upload
                            </Button>
                        </form>
                    </DataPanel>
                )}

                {status === 'draft' && canUpdate && (
                    <DataPanel title="Publish">
                        <p className="mb-3 text-sm text-slate-500">
                            This draft is only visible to you. Publish it to send it to the approval
                            queue.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            {showEdit && (
                                <Link href={`/requisitions/${requisition.id}/edit`}>
                                    <Button type="button" variant="outline">
                                        <Pencil className="h-4 w-4" />
                                        Edit Draft
                                    </Button>
                                </Link>
                            )}
                            <form onSubmit={(e) => transition(e, 'under_review')}>
                                <Button type="submit" disabled={transitionForm.processing}>
                                    Publish for Approval
                                </Button>
                            </form>
                        </div>
                    </DataPanel>
                )}

                {status === 'rejected' && canUpdate && showEdit && (
                    <DataPanel title="Actions">
                        <Link href={`/requisitions/${requisition.id}/edit`}>
                            <Button type="button" variant="outline">
                                <Pencil className="h-4 w-4" />
                                Revise & Return to Draft
                            </Button>
                        </Link>
                    </DataPanel>
                )}

                {canDecide && pendingStep && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <DataPanel title="Approve">
                            <p className="mb-3 text-xs text-slate-500">
                                Pending step: {pendingStep.required_role}
                            </p>
                            <form
                                onSubmit={(e) => submitResolve(approveForm, e)}
                                className="space-y-3"
                            >
                                <div className="space-y-2">
                                    <Label>Comment (optional)</Label>
                                    <Input
                                        value={approveForm.data.comment}
                                        onChange={(e) =>
                                            approveForm.setData('comment', e.target.value)
                                        }
                                    />
                                </div>
                                {showOverride && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={approveForm.data.override}
                                            onChange={(e) =>
                                                approveForm.setData('override', e.target.checked)
                                            }
                                        />
                                        Override BOQ / cash limits
                                    </label>
                                )}
                                <Button
                                    type="submit"
                                    disabled={approveForm.processing}
                                    className="bg-green-700 hover:bg-green-800"
                                >
                                    Approve
                                </Button>
                            </form>
                        </DataPanel>

                        {hasPermission(auth.user, 'requisitions', 'amend') && pendingStep && (
                            <DataPanel
                                title="Amend"
                                description="Edit line quantities and costs. Total is derived from the amended lines."
                            >
                                <AmendRequisitionForm
                                    items={requisition.items ?? []}
                                    originalAmount={String(requisition.original_amount)}
                                    resolveUrl={`/approvals/steps/${pendingStep.id}/resolve`}
                                    showOverride={showOverride}
                                />
                            </DataPanel>
                        )}

                        <DataPanel title="Reject">
                            <form
                                onSubmit={(e) => submitResolve(rejectForm, e)}
                                className="space-y-3"
                            >
                                <div className="space-y-2">
                                    <Label>Comment</Label>
                                    <Input
                                        value={rejectForm.data.comment}
                                        onChange={(e) =>
                                            rejectForm.setData('comment', e.target.value)
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={rejectForm.processing}
                                    className="border-red-200 text-red-700 hover:bg-red-50"
                                >
                                    Reject
                                </Button>
                            </form>
                        </DataPanel>
                    </div>
                )}

                {(status === 'approved' || status === 'amended') && canFulfill && (
                    <DataPanel title="Fulfill">
                        <form onSubmit={(e) => transition(e, 'fulfilled')} className="space-y-4">
                            {isStockFulfillment ? (
                                <div className="space-y-4">
                                    <div className="flex flex-wrap gap-4 text-sm">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="inventory_source"
                                                checked={
                                                    transitionForm.data.inventory_source ===
                                                    'existing'
                                                }
                                                onChange={() =>
                                                    transitionForm.setData(
                                                        'inventory_source',
                                                        'existing',
                                                    )
                                                }
                                            />
                                            Existing inventory item
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="inventory_source"
                                                checked={
                                                    transitionForm.data.inventory_source === 'new'
                                                }
                                                onChange={() =>
                                                    transitionForm.setData('inventory_source', 'new')
                                                }
                                            />
                                            Create new inventory item
                                        </label>
                                    </div>

                                    {transitionForm.data.inventory_source === 'existing' ? (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label>Inventory Item</Label>
                                                <select
                                                    className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                    value={transitionForm.data.inventory_item_id}
                                                    onChange={(e) =>
                                                        transitionForm.setData(
                                                            'inventory_item_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="">Select item…</option>
                                                    {inventoryItems.map((item) => (
                                                        <option key={item.id} value={item.id}>
                                                            {item.code} — {item.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Stock Location</Label>
                                                <select
                                                    className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                    value={transitionForm.data.stock_location_id}
                                                    onChange={(e) =>
                                                        transitionForm.setData(
                                                            'stock_location_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="">Select location…</option>
                                                    {stockLocations.map((loc) => (
                                                        <option key={loc.id} value={loc.id}>
                                                            {loc.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="space-y-3 rounded-md border border-slate-200 p-3">
                                            <p className="text-xs text-slate-500">
                                                Creates the catalog item, receives stock at the
                                                location (defaults to requested qty), then issues it
                                                against this requisition.
                                            </p>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Name</Label>
                                                    <Input
                                                        value={
                                                            transitionForm.data.new_inventory_item
                                                                .name
                                                        }
                                                        onChange={(e) =>
                                                            transitionForm.setData(
                                                                'new_inventory_item',
                                                                {
                                                                    ...transitionForm.data
                                                                        .new_inventory_item,
                                                                    name: e.target.value,
                                                                },
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Unit</Label>
                                                    <Input
                                                        value={
                                                            transitionForm.data.new_inventory_item
                                                                .unit
                                                        }
                                                        onChange={(e) =>
                                                            transitionForm.setData(
                                                                'new_inventory_item',
                                                                {
                                                                    ...transitionForm.data
                                                                        .new_inventory_item,
                                                                    unit: e.target.value,
                                                                },
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Category</Label>
                                                    <select
                                                        className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                        value={
                                                            transitionForm.data.new_inventory_item
                                                                .category
                                                        }
                                                        onChange={(e) =>
                                                            transitionForm.setData(
                                                                'new_inventory_item',
                                                                {
                                                                    ...transitionForm.data
                                                                        .new_inventory_item,
                                                                    category: e.target.value,
                                                                },
                                                            )
                                                        }
                                                        required
                                                    >
                                                        {inventoryCategories.map((cat) => (
                                                            <option key={cat.value} value={cat.value}>
                                                                {cat.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Stock Location</Label>
                                                    <select
                                                        className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                        value={transitionForm.data.stock_location_id}
                                                        onChange={(e) =>
                                                            transitionForm.setData(
                                                                'stock_location_id',
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    >
                                                        <option value="">Select location…</option>
                                                        {stockLocations.map((loc) => (
                                                            <option key={loc.id} value={loc.id}>
                                                                {loc.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Code (optional)</Label>
                                                    <Input
                                                        value={
                                                            transitionForm.data.new_inventory_item
                                                                .code
                                                        }
                                                        onChange={(e) =>
                                                            transitionForm.setData(
                                                                'new_inventory_item',
                                                                {
                                                                    ...transitionForm.data
                                                                        .new_inventory_item,
                                                                    code: e.target.value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Receive qty (optional)</Label>
                                                    <AmountInput
                                                        value={
                                                            transitionForm.data.new_inventory_item
                                                                .receive_quantity
                                                        }
                                                        onValueChange={(v) =>
                                                            transitionForm.setData(
                                                                'new_inventory_item',
                                                                {
                                                                    ...transitionForm.data
                                                                        .new_inventory_item,
                                                                    receive_quantity: v,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Unit cost (optional)</Label>
                                                    <AmountInput
                                                        value={
                                                            transitionForm.data.new_inventory_item
                                                                .unit_cost
                                                        }
                                                        onValueChange={(v) =>
                                                            transitionForm.setData(
                                                                'new_inventory_item',
                                                                {
                                                                    ...transitionForm.data
                                                                        .new_inventory_item,
                                                                    unit_cost: v,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <p className="text-xs text-slate-500">
                                        Disbursement note — records who received the cash and how.
                                        Amount is deducted from finance cash on hand for this project.
                                    </p>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Account / party received</Label>
                                            <Input
                                                value={transitionForm.data.payee}
                                                onChange={(e) =>
                                                    transitionForm.setData('payee', e.target.value)
                                                }
                                                placeholder="Name or account"
                                                required
                                            />
                                            {transitionForm.errors.payee && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.payee}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Reference number</Label>
                                            <Input
                                                value={transitionForm.data.reference_no}
                                                onChange={(e) =>
                                                    transitionForm.setData(
                                                        'reference_no',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Receipt / voucher / TXN ref"
                                                required
                                            />
                                            {transitionForm.errors.reference_no && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.reference_no}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Payment method</Label>
                                            <PaymentMethodSelect
                                                value={transitionForm.data.method}
                                                onChange={(e) =>
                                                    transitionForm.setData('method', e.target.value)
                                                }
                                            />
                                            {transitionForm.errors.method && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.method}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Amount to disburse</Label>
                                            <p className="flex h-10 items-center text-sm font-medium text-slate-900">
                                                {formatCurrency(amount)}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                Cash on hand: {formatCurrency(cashOnHand)}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}
                            <Button type="submit" disabled={transitionForm.processing}>
                                Mark Fulfilled
                            </Button>
                        </form>
                    </DataPanel>
                )}

                {(status === 'approved' ||
                    status === 'amended' ||
                    status === 'draft' ||
                    status === 'rejected' ||
                    status === 'under_review' ||
                    status === 'fulfilled') &&
                    (hasPermission(auth.user, 'requisitions', 'cancel') ||
                        (status === 'draft' && canUpdate)) && (
                    <DataPanel
                        title="Delete requisition"
                        description="Remove a wrongly created request. Approved amounts and cash effects are reversed."
                    >
                        <Button
                            type="button"
                            variant="outline"
                            className="border-red-300 text-red-700 hover:bg-red-50"
                            onClick={() => {
                                if (
                                    !confirm(
                                        'Delete this requisition? This cannot be undone from the list.',
                                    )
                                ) {
                                    return;
                                }
                                router.delete(`/requisitions/${requisition.id}`);
                            }}
                        >
                            Delete requisition
                        </Button>
                    </DataPanel>
                )}

                {status === 'fulfilled' && canUpdate && (
                    <DataPanel title="Close">
                        <p className="mb-3 text-sm text-slate-500">
                            Closing requires at least one attachment (receipt, GRN, or invoice).
                        </p>
                        <form onSubmit={(e) => transition(e, 'closed')}>
                            <Button type="submit" disabled={transitionForm.processing}>
                                Close Requisition
                            </Button>
                        </form>
                    </DataPanel>
                )}

                {(status === 'approved' || status === 'amended') && canUpdate && (
                    <DataPanel title="Cancel">
                        <form onSubmit={(e) => transition(e, 'cancelled')} className="space-y-3">
                            <Input
                                placeholder="Cancellation reason (optional)"
                                value={transitionForm.data.comment}
                                onChange={(e) =>
                                    transitionForm.setData('comment', e.target.value)
                                }
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={transitionForm.processing}
                                className="border-red-300 text-red-700"
                            >
                                Cancel Requisition
                            </Button>
                        </form>
                    </DataPanel>
                )}
            </div>
        </AppShell>
    );
}
