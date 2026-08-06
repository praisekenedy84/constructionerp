import AppShell from '@/Components/Layout/AppShell';
import AmendRequisitionForm from '@/Components/Domain/AmendRequisitionForm';
import CashShortfallApproveDialog from '@/Components/Domain/CashShortfallApproveDialog';
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
    CashAvailability,
    InventoryItem,
    PageProps,
    Requisition,
    StockLocation,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { ClipboardCheck, ExternalLink, Pencil } from 'lucide-react';

interface CategoryOption {
    value: string;
    label: string;
}

type RecipientPaymentDraft = {
    recipient_key: string;
    recipient_id: number | null;
    name: string;
    selected: boolean;
    account_name: string;
    account_number: string;
    method: string;
    payment_date: string;
    reference_no: string;
    remaining_amount: number;
    items: { requisition_item_id: number; quantity: string }[];
};

function lineRemainingAmount(item: NonNullable<Requisition['items']>[number]): {
    quantity: number;
    amount: number;
} {
    const quantity = Math.max(
        0,
        Number(item.quantity) - Number(item.fulfilled_quantity ?? 0),
    );
    const daysRaw = item.details?.days;
    const days = daysRaw != null && Number(daysRaw) > 0 ? Number(daysRaw) : 1;
    return { quantity, amount: quantity * Number(item.unit_cost) * days };
}

function buildRecipientPaymentDrafts(
    items: NonNullable<Requisition['items']>,
    paymentDate: string,
): RecipientPaymentDraft[] {
    const groups = new Map<string, RecipientPaymentDraft>();

    for (const item of items) {
        const remaining = lineRemainingAmount(item);
        if (remaining.quantity <= 0) {
            continue;
        }

        const recipientId = item.recipient_id ?? null;
        const name =
            (item.recipient_name && item.recipient_name !== '—'
                ? item.recipient_name
                : null) ??
            item.recipient?.name ??
            'Unassigned';
        const key = recipientId ? `id:${recipientId}` : `name:${name}`;

        const existing = groups.get(key);
        if (existing) {
            existing.remaining_amount += remaining.amount;
            existing.items.push({
                requisition_item_id: item.id,
                quantity: String(remaining.quantity),
            });
            continue;
        }

        groups.set(key, {
            recipient_key: key.startsWith('id:') ? key : `name:${name}`,
            recipient_id: recipientId,
            name,
            selected: true,
            account_name: name === 'Unassigned' ? '' : name,
            account_number: '',
            method: 'cash',
            payment_date: paymentDate,
            reference_no: '',
            remaining_amount: remaining.amount,
            items: [
                {
                    requisition_item_id: item.id,
                    quantity: String(remaining.quantity),
                },
            ],
        });
    }

    return Array.from(groups.values());
}

interface RequisitionsShowProps extends PageProps {
    requisition: Requisition;
    pendingStep: ApprovalStep | null;
    inventoryItems: InventoryItem[];
    stockLocations: StockLocation[];
    inventoryCategories: CategoryOption[];
    cashOnHand: string;
    cashAvailability?: CashAvailability | null;
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
        cashAvailability = null,
        canEdit,
        canDecide,
        pendingStep,
    } = usePage<RequisitionsShowProps>().props;

    const today = new Date().toISOString().slice(0, 10);
    const isStockFulfillment =
        requisition.addressed_to === 'storekeeper' ||
        (!requisition.addressed_to && requisition.fulfillment_type === 'stock_issue');
    const remainingRecipientPayments = buildRecipientPaymentDrafts(
        requisition.items ?? [],
        today,
    );
    const distinctRecipientCount = new Set(
        (requisition.items ?? []).map((item) => {
            if (item.recipient_id) {
                return `id:${item.recipient_id}`;
            }
            const name =
                (item.recipient_name && item.recipient_name !== '—'
                    ? item.recipient_name
                    : null) ??
                item.recipient?.name ??
                'Unassigned';
            return `name:${name}`;
        }),
    ).size;
    // Keep per-recipient payments whenever the request has multiple recipients,
    // including after a partial payment leaves only one unpaid.
    const usePerRecipientPayments =
        !isStockFulfillment &&
        remainingRecipientPayments.length > 0 &&
        distinctRecipientCount > 1;

    const [recipientPayments, setRecipientPayments] = useState<RecipientPaymentDraft[]>(
        remainingRecipientPayments,
    );

    useEffect(() => {
        setRecipientPayments(remainingRecipientPayments);
    }, [
        requisition.id,
        requisition.fulfilled_amount,
        requisition.status,
        // Rebuild when remaining line quantities change after a partial payment.
        (requisition.items ?? [])
            .map((item) => `${item.id}:${item.fulfilled_quantity ?? 0}`)
            .join('|'),
    ]);

    const transitionForm = useForm({
        to_status: '',
        comment: '',
        fulfillment_scope: (requisition.fulfillment_scope ??
            (usePerRecipientPayments ? 'items' : 'whole')) as 'whole' | 'items',
        amount: String(
            Math.max(
                0,
                Number(requisition.amended_amount ?? requisition.original_amount) -
                    Number(requisition.fulfilled_amount ?? 0),
            ),
        ),
        items: (requisition.items ?? []).map((item) => ({
            requisition_item_id: item.id,
            quantity: '',
        })),
        inventory_source: 'existing' as 'existing' | 'new',
        inventory_item_id: String(
            requisition.items?.find((item) => item.inventory_item_id)?.inventory_item_id ?? '',
        ),
        stock_location_id: '',
        method: 'cash',
        payee: '',
        account_name: '',
        account_number: '',
        payment_date: today,
        reference_no: '',
        payments: [] as Array<{
            recipient_key: string;
            recipient_id: number | null;
            payee: string;
            account_name: string;
            account_number: string;
            method: string;
            payment_date: string;
            reference_no: string;
            items: { requisition_item_id: number; quantity: string }[];
        }>,
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
    const canAmend = hasPermission(auth.user, 'requisitions', 'amend');
    const exceedsCash = Boolean(cashAvailability?.exceeds);
    const [cashDialogOpen, setCashDialogOpen] = useState(false);
    const amendSectionRef = useRef<HTMLDivElement>(null);
    const rejectSectionRef = useRef<HTMLDivElement>(null);

    function transition(e: FormEvent, toStatus: string) {
        e.preventDefault();
        transitionForm.transform((data) => {
            const selectedPayments = usePerRecipientPayments
                ? recipientPayments
                      .filter((payment) => payment.selected)
                      .map((payment) => ({
                          recipient_key: payment.recipient_key,
                          recipient_id: payment.recipient_id,
                          payee: payment.account_name,
                          account_name: payment.account_name,
                          account_number: payment.account_number,
                          method: payment.method,
                          payment_date: payment.payment_date,
                          reference_no: payment.reference_no,
                          items: payment.items,
                      }))
                : [];

            return {
                ...data,
                to_status: toStatus,
                fulfillment_scope:
                    toStatus === 'fulfilled' && usePerRecipientPayments
                        ? 'items'
                        : data.fulfillment_scope,
                payments: toStatus === 'fulfilled' ? selectedPayments : [],
                items:
                    toStatus === 'fulfilled' &&
                    data.fulfillment_scope === 'items' &&
                    !usePerRecipientPayments
                        ? data.items.filter((item) => Number(item.quantity) > 0)
                        : [],
            };
        });
        transitionForm.post(`/requisitions/${requisition.id}/transition`, {
            onFinish: () => transitionForm.transform((data) => data),
        });
    }

    function uploadAttachment(e: FormEvent) {
        e.preventDefault();
        attachmentForm.post(`/requisitions/${requisition.id}/attachments`, {
            forceFormData: true,
        });
    }

    function submitResolve(
        form: ReturnType<typeof useForm>,
        e?: FormEvent,
    ) {
        e?.preventDefault();
        if (!pendingStep) {
            return;
        }
        form.post(`/approvals/steps/${pendingStep.id}/resolve`);
    }

    function handleApproveSubmit(e: FormEvent) {
        e.preventDefault();
        if (!pendingStep) {
            return;
        }

        if (exceedsCash && !approveForm.data.override) {
            setCashDialogOpen(true);
            return;
        }

        submitResolve(approveForm);
    }

    function focusSection(ref: { current: HTMLDivElement | null }) {
        setCashDialogOpen(false);
        window.requestAnimationFrame(() => {
            ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function approveWithOverride() {
        if (!pendingStep) {
            return;
        }
        setCashDialogOpen(false);
        approveForm.transform((data) => ({ ...data, override: true }));
        approveForm.post(`/approvals/steps/${pendingStep.id}/resolve`, {
            onFinish: () => {
                approveForm.transform((data) => data);
            },
        });
    }

    const amount = requisition.amended_amount ?? requisition.original_amount;
    const fulfilledAmount = Number(requisition.fulfilled_amount ?? 0);
    const remainingAmount = Math.max(0, Number(amount) - fulfilledAmount);
    const itemFulfillmentAmount = (requisition.items ?? []).reduce((total, item, index) => {
        const quantity = Number(transitionForm.data.items[index]?.quantity ?? 0);
        const daysRaw = item.details?.days;
        const days = daysRaw != null && Number(daysRaw) > 0 ? Number(daysRaw) : 1;
        return total + quantity * Number(item.unit_cost) * days;
    }, 0);
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
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');
    const canPublish = hasPermission(auth.user, 'requisitions', 'publish');
    const canFulfill = hasPermission(auth.user, 'requisitions', 'fulfill');
    const showEdit = Boolean(canEdit) && canUpdate;
    const hasLineAmendments = (requisition.items ?? []).some(
        (item) => item.original_quantity != null || item.original_line_total != null,
    );
    const selectedRecipientPaymentTotal = recipientPayments
        .filter((payment) => payment.selected)
        .reduce((sum, payment) => sum + payment.remaining_amount, 0);

    return (
        <AppShell title={requisition.requisition_no}>
            <Head title={requisition.requisition_no} />
            <div className="space-y-6">
                <PageHeader
                    title={requisition.requisition_no}
                    description={`Requested by ${requisition.requestor?.name ?? 'Unknown'}${
                        (requisition.recipients?.length ?? 0) > 0 || requisition.recipient_name
                            ? ` · On behalf of ${
                                  (requisition.recipients?.length ?? 0) > 0
                                      ? requisition.recipients
                                            ?.map((r) =>
                                                [r.name === '—' ? null : r.name, r.position_name]
                                                    .filter(Boolean)
                                                    .join(' · '),
                                            )
                                            .filter(Boolean)
                                            .join('; ')
                                      : `${requisition.recipient_name ?? ''}${
                                            requisition.recipient_position
                                                ? ` (${requisition.recipient_position})`
                                                : ''
                                        }`
                              }`
                            : ''
                    } · ${requisition.department} · ${requisition.project?.name ?? 'Administrative'}`}
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

                {((requisition.recipients?.length ?? 0) > 0 ||
                    requisition.recipient_name ||
                    requisition.recipient_position) && (
                    <DataPanel title="Recipients (on behalf of)">
                        {(requisition.recipients?.length ?? 0) > 0 ? (
                            <ul className="divide-y divide-slate-100 text-sm">
                                {requisition.recipients?.map((recipient, index) => (
                                    <li
                                        key={recipient.id ?? index}
                                        className="flex flex-wrap justify-between gap-2 py-2"
                                    >
                                        <span className="font-medium text-slate-900">
                                            {recipient.name === '—' ? '—' : recipient.name}
                                        </span>
                                        <span className="text-slate-600">
                                            {recipient.position_name || '—'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 text-sm">
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                        Name
                                    </p>
                                    <p className="mt-1 text-slate-900">
                                        {requisition.recipient_name || '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                        Position
                                    </p>
                                    <p className="mt-1 text-slate-900">
                                        {requisition.recipient_position || '—'}
                                    </p>
                                </div>
                            </div>
                        )}
                    </DataPanel>
                )}

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
                            {formatCurrency(cashAvailability?.cash_on_hand ?? cashOnHand)}
                        </p>
                        {cashAvailability && (
                            <p
                                className={`mt-1 text-xs ${
                                    exceedsCash ? 'font-medium text-amber-700' : 'text-slate-500'
                                }`}
                            >
                                Available after commitments:{' '}
                                {formatCurrency(cashAvailability.available)}
                                {exceedsCash ? ' · Below this request' : ''}
                            </p>
                        )}
                        <p className="mt-1 text-xs capitalize text-slate-500">
                            {requisition.project_id ? 'Project float' : 'Administrative float'} · To{' '}
                            {String(
                                requisition.addressed_to ??
                                    (isStockFulfillment ? 'storekeeper' : 'finance'),
                            )}{' '}
                            · {(requisition.categories?.length ?? 0) > 0
                                ? requisition.categories?.map((c) => c.name).join(', ')
                                : requisition.category?.name ??
                                String(requisition.resource_type ?? '—').replace(/_/g, ' ')}{' '}
                            ·{' '}
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
                                    <th className="pb-2 font-medium">Category</th>
                                    <th className="pb-2 font-medium">Recipient</th>
                                    <th className="pb-2 font-medium">Unit</th>
                                    <th className="pb-2 text-right font-medium">Qty</th>
                                    <th className="pb-2 text-right font-medium">Days</th>
                                    <th className="pb-2 text-right font-medium">Fulfilled</th>
                                    <th className="pb-2 text-right font-medium">Remaining</th>
                                    <th className="pb-2 text-right font-medium">Unit Cost</th>
                                    <th className="pb-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(requisition.items ?? []).map((item) => {
                                    const changed =
                                        item.original_line_total != null &&
                                        String(item.original_line_total) !== String(item.line_total);
                                    const recipientLabel = [
                                        item.recipient_name && item.recipient_name !== '—'
                                            ? item.recipient_name
                                            : null,
                                        item.recipient_position,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ');
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
                                            {item.inventory_item && (
                                                <div className="text-xs text-slate-500">
                                                    Catalog: {item.inventory_item.code}
                                                </div>
                                            )}
                                        </td>
                                        <td className="py-2 text-slate-600">
                                            {item.category?.name ?? '—'}
                                        </td>
                                        <td className="py-2 text-slate-600">
                                            {recipientLabel || '—'}
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
                                            {item.details?.days != null &&
                                            Number(item.details.days) > 0
                                                ? formatQuantity(item.details.days)
                                                : '—'}
                                        </td>
                                        <td className="py-2 text-right text-slate-600">
                                            {formatQuantity(item.fulfilled_quantity ?? 0)}
                                        </td>
                                        <td className="py-2 text-right font-medium text-slate-800">
                                            {formatQuantity(
                                                Math.max(
                                                    0,
                                                    Number(item.quantity) -
                                                        Number(item.fulfilled_quantity ?? 0),
                                                ),
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
                                    <td colSpan={9} className="pt-3 text-right font-medium">
                                        {hasLineAmendments ? 'Amended total' : 'Total'}
                                    </td>
                                    <td className="pt-3 text-right font-bold text-slate-900">
                                        {formatCurrency(amount)}
                                    </td>
                                </tr>
                                {hasLineAmendments && (
                                    <tr>
                                        <td
                                            colSpan={9}
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

                {status === 'draft' && canPublish && (
                    <DataPanel title="Publish">
                        <p className="mb-3 text-sm text-slate-500">
                            Drafts stay private to the author until published. Administrators can also
                            publish on behalf of the author.
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
                            <form onSubmit={handleApproveSubmit} className="space-y-3">
                                <div className="space-y-2">
                                    <Label>Comment (optional)</Label>
                                    <Input
                                        value={approveForm.data.comment}
                                        onChange={(e) =>
                                            approveForm.setData('comment', e.target.value)
                                        }
                                    />
                                </div>
                                {showOverride && !exceedsCash && (
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
                                {exceedsCash && (
                                    <p className="text-xs text-amber-700">
                                        This request exceeds available cash. Amend or reject —
                                        approved requests cannot be amended later
                                        {showOverride
                                            ? '. Override is available in the reminder dialog'
                                            : ''}
                                        .
                                    </p>
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

                        {canAmend && (
                            <div ref={amendSectionRef}>
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
                            </div>
                        )}

                        <div ref={rejectSectionRef}>
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
                    </div>
                )}

                {(status === 'approved' ||
                    status === 'amended' ||
                    status === 'partially_fulfilled') &&
                    canFulfill && (
                    <DataPanel title="Fulfill">
                        <form onSubmit={(e) => transition(e, 'fulfilled')} className="space-y-4">
                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span>
                                        Fulfilled {formatCurrency(fulfilledAmount)} of{' '}
                                        {formatCurrency(amount)}
                                    </span>
                                    <span className="font-medium text-slate-900">
                                        Remaining {formatCurrency(remainingAmount)}
                                    </span>
                                </div>
                            </div>

                            {usePerRecipientPayments ? (
                                <div className="space-y-4">
                                    <p className="text-sm text-slate-600">
                                        Record a separate payment for each recipient according to
                                        their request lines. Select who to pay now.
                                    </p>
                                    {recipientPayments.map((payment, index) => (
                                        <div
                                            key={payment.recipient_key}
                                            className="space-y-3 rounded-lg border border-slate-200 p-4"
                                        >
                                            <label className="flex items-start gap-3">
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={payment.selected}
                                                    onChange={(e) => {
                                                        const next = [...recipientPayments];
                                                        next[index] = {
                                                            ...payment,
                                                            selected: e.target.checked,
                                                        };
                                                        setRecipientPayments(next);
                                                    }}
                                                />
                                                <div>
                                                    <p className="font-medium text-slate-900">
                                                        {payment.name}
                                                    </p>
                                                    <p className="text-sm text-slate-500">
                                                        {payment.items.length} line
                                                        {payment.items.length === 1 ? '' : 's'} ·{' '}
                                                        {formatCurrency(payment.remaining_amount)}
                                                    </p>
                                                </div>
                                            </label>
                                            {payment.selected && (
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <div className="space-y-2">
                                                        <Label>Account name</Label>
                                                        <Input
                                                            value={payment.account_name}
                                                            onChange={(e) => {
                                                                const next = [...recipientPayments];
                                                                next[index] = {
                                                                    ...payment,
                                                                    account_name: e.target.value,
                                                                };
                                                                setRecipientPayments(next);
                                                            }}
                                                            required
                                                        />
                                                        {transitionForm.errors[
                                                            `payments.${index}.account_name`
                                                        ] && (
                                                            <p className="text-sm text-red-600">
                                                                {
                                                                    transitionForm.errors[
                                                                        `payments.${index}.account_name`
                                                                    ]
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Account number</Label>
                                                        <Input
                                                            value={payment.account_number}
                                                            onChange={(e) => {
                                                                const next = [...recipientPayments];
                                                                next[index] = {
                                                                    ...payment,
                                                                    account_number: e.target.value,
                                                                };
                                                                setRecipientPayments(next);
                                                            }}
                                                            required
                                                        />
                                                        {transitionForm.errors[
                                                            `payments.${index}.account_number`
                                                        ] && (
                                                            <p className="text-sm text-red-600">
                                                                {
                                                                    transitionForm.errors[
                                                                        `payments.${index}.account_number`
                                                                    ]
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Date received</Label>
                                                        <Input
                                                            type="date"
                                                            value={payment.payment_date}
                                                            onChange={(e) => {
                                                                const next = [...recipientPayments];
                                                                next[index] = {
                                                                    ...payment,
                                                                    payment_date: e.target.value,
                                                                };
                                                                setRecipientPayments(next);
                                                            }}
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Reference number</Label>
                                                        <Input
                                                            value={payment.reference_no}
                                                            onChange={(e) => {
                                                                const next = [...recipientPayments];
                                                                next[index] = {
                                                                    ...payment,
                                                                    reference_no: e.target.value,
                                                                };
                                                                setRecipientPayments(next);
                                                            }}
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Payment method</Label>
                                                        <PaymentMethodSelect
                                                            value={payment.method}
                                                            onChange={(e) => {
                                                                const next = [...recipientPayments];
                                                                next[index] = {
                                                                    ...payment,
                                                                    method: e.target.value,
                                                                };
                                                                setRecipientPayments(next);
                                                            }}
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Amount</Label>
                                                        <p className="flex h-10 items-center text-sm font-medium text-slate-900">
                                                            {formatCurrency(payment.remaining_amount)}
                                                        </p>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span className="text-slate-500">
                                            Cash on hand: {formatCurrency(cashOnHand)}
                                        </span>
                                        <span className="font-medium text-slate-900">
                                            Paying now:{' '}
                                            {formatCurrency(selectedRecipientPaymentTotal)}
                                        </span>
                                    </div>
                                    {transitionForm.errors.payments && (
                                        <p className="text-sm text-red-600">
                                            {transitionForm.errors.payments}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <>
                            <div className="space-y-2">
                                <Label>Fulfillment method</Label>
                                <div className="flex flex-wrap gap-4 text-sm">
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="fulfillment_scope"
                                            checked={
                                                transitionForm.data.fulfillment_scope === 'whole'
                                            }
                                            disabled={Boolean(requisition.fulfillment_scope)}
                                            onChange={() =>
                                                transitionForm.setData(
                                                    'fulfillment_scope',
                                                    'whole',
                                                )
                                            }
                                        />
                                        {isStockFulfillment
                                            ? 'Fulfill all remaining items'
                                            : 'By request amount'}
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="fulfillment_scope"
                                            checked={
                                                transitionForm.data.fulfillment_scope === 'items'
                                            }
                                            disabled={Boolean(requisition.fulfillment_scope)}
                                            onChange={() =>
                                                transitionForm.setData(
                                                    'fulfillment_scope',
                                                    'items',
                                                )
                                            }
                                        />
                                        By selected line items
                                    </label>
                                </div>
                                {requisition.fulfillment_scope && (
                                    <p className="text-xs text-slate-500">
                                        The method is locked after the first fulfillment.
                                    </p>
                                )}
                            </div>

                            {transitionForm.data.fulfillment_scope === 'items' && (
                                <div className="overflow-hidden rounded-md border border-slate-200">
                                    <table className="w-full text-sm">
                                        <thead className="bg-slate-50 text-left text-xs text-slate-500">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">Item</th>
                                                <th className="px-3 py-2 text-right font-medium">
                                                    Remaining
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Fulfill now
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {(requisition.items ?? []).map((item, index) => {
                                                const remaining = Math.max(
                                                    0,
                                                    Number(item.quantity) -
                                                        Number(item.fulfilled_quantity ?? 0),
                                                );
                                                return (
                                                    <tr key={item.id}>
                                                        <td className="px-3 py-2 text-slate-800">
                                                            {item.description}
                                                        </td>
                                                        <td className="px-3 py-2 text-right text-slate-600">
                                                            {formatQuantity(remaining)}{' '}
                                                            {item.unit ?? ''}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <AmountInput
                                                                value={
                                                                    transitionForm.data.items[index]
                                                                        ?.quantity ?? ''
                                                                }
                                                                onValueChange={(value) => {
                                                                    const items = [
                                                                        ...transitionForm.data.items,
                                                                    ];
                                                                    items[index] = {
                                                                        ...items[index],
                                                                        quantity: value,
                                                                    };
                                                                    transitionForm.setData(
                                                                        'items',
                                                                        items,
                                                                    );
                                                                }}
                                                                max={remaining}
                                                                disabled={remaining <= 0}
                                                            />
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                    <p className="border-t border-slate-200 px-3 py-2 text-right text-sm font-medium">
                                        This fulfillment: {formatCurrency(itemFulfillmentAmount)}
                                    </p>
                                    {transitionForm.errors.items && (
                                        <p className="px-3 pb-2 text-sm text-red-600">
                                            {transitionForm.errors.items}
                                        </p>
                                    )}
                                </div>
                            )}

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
                                        Amount is deducted from{' '}
                                        {requisition.project_id
                                            ? 'project cash on hand and recorded as a direct expense'
                                            : 'administrative cash on hand and recorded as overhead'}
                                        .
                                    </p>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Account name</Label>
                                            <Input
                                                value={transitionForm.data.account_name}
                                                onChange={(e) => {
                                                    transitionForm.setData(
                                                        'account_name',
                                                        e.target.value,
                                                    );
                                                    transitionForm.setData('payee', e.target.value);
                                                }}
                                                placeholder="Account / recipient name"
                                                required
                                            />
                                            {transitionForm.errors.account_name && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.account_name}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Account number</Label>
                                            <Input
                                                value={transitionForm.data.account_number}
                                                onChange={(e) =>
                                                    transitionForm.setData(
                                                        'account_number',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Bank / mobile account number"
                                                required
                                            />
                                            {transitionForm.errors.account_number && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.account_number}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Date received</Label>
                                            <Input
                                                type="date"
                                                value={transitionForm.data.payment_date}
                                                onChange={(e) =>
                                                    transitionForm.setData(
                                                        'payment_date',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                            {transitionForm.errors.payment_date && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.payment_date}
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
                                            <Label>
                                                {transitionForm.data.fulfillment_scope === 'whole'
                                                    ? 'Amount to disburse'
                                                    : 'Calculated amount'}
                                            </Label>
                                            {transitionForm.data.fulfillment_scope === 'whole' ? (
                                                <AmountInput
                                                    value={transitionForm.data.amount}
                                                    onValueChange={(value) =>
                                                        transitionForm.setData('amount', value)
                                                    }
                                                    max={remainingAmount}
                                                    required
                                                />
                                            ) : (
                                                <p className="flex h-10 items-center text-sm font-medium text-slate-900">
                                                    {formatCurrency(itemFulfillmentAmount)}
                                                </p>
                                            )}
                                            {transitionForm.errors.amount && (
                                                <p className="text-sm text-red-600">
                                                    {transitionForm.errors.amount}
                                                </p>
                                            )}
                                            <p className="text-xs text-slate-500">
                                                Cash on hand: {formatCurrency(cashOnHand)}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}
                                </>
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    transitionForm.processing ||
                                    (usePerRecipientPayments &&
                                        !recipientPayments.some((payment) => payment.selected))
                                }
                            >
                                Record Fulfillment
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

            {cashAvailability && (
                <CashShortfallApproveDialog
                    open={cashDialogOpen}
                    onOpenChange={setCashDialogOpen}
                    availability={cashAvailability}
                    canAmend={canAmend}
                    canOverride={showOverride}
                    overrideChecked={approveForm.data.override}
                    onOverrideChange={(checked) => approveForm.setData('override', checked)}
                    onAmend={() => focusSection(amendSectionRef)}
                    onReject={() => focusSection(rejectSectionRef)}
                    onApproveWithOverride={approveWithOverride}
                    processing={approveForm.processing}
                />
            )}
        </AppShell>
    );
}
