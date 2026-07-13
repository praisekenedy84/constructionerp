import AppShell from '@/Components/Layout/AppShell';
import RequisitionTimeline from '@/Components/Domain/RequisitionTimeline';
import DataPanel from '@/Components/Shared/DataPanel';
import { IconLink } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import {
    ApprovalStep,
    InventoryItem,
    PageProps,
    Requisition,
    StockLocation,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { ExternalLink } from 'lucide-react';

interface RequisitionsShowProps extends PageProps {
    requisition: Requisition;
    pendingStep: ApprovalStep | null;
    inventoryItems: InventoryItem[];
    stockLocations: StockLocation[];
    cashOnHand: string;
}

export default function RequisitionsShow() {
    const { requisition, auth, inventoryItems, stockLocations, cashOnHand } =
        usePage<RequisitionsShowProps>().props;

    const transitionForm = useForm({
        to_status: '',
        comment: '',
        inventory_item_id: '',
        stock_location_id: '',
        method: 'cash',
        payee: '',
    });
    const attachmentForm = useForm<{ file: File | null; document_type: string }>({
        file: null,
        document_type: 'receipt',
    });

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

    const amount = requisition.amended_amount ?? requisition.original_amount;
    const history =
        requisition.history ??
        (requisition as Requisition & { status_histories?: Requisition['history'] }).status_histories ??
        [];
    const fulfillmentLabel = String(requisition.fulfillment_type).replace(/_/g, ' ');
    const status = String(requisition.status);
    const isStockFulfillment = requisition.fulfillment_type === 'stock_issue';
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');
    const canFulfill = hasPermission(auth.user, 'requisitions', 'fulfill');

    return (
        <AppShell title={requisition.requisition_no}>
            <Head title={requisition.requisition_no} />
            <div className="space-y-6">
                <PageHeader
                    title={requisition.requisition_no}
                    description={`${requisition.department} · ${requisition.project?.name ?? ''}`}
                    actions={<StatusBadge status={status} />}
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
                    <DataPanel title="Fulfillment">
                        <p className="text-sm font-medium capitalize text-slate-700">
                            {fulfillmentLabel}
                        </p>
                    </DataPanel>
                    <DataPanel title="Cash on Hand">
                        <p className="text-sm font-medium text-slate-700">
                            {formatCurrency(cashOnHand)}
                        </p>
                    </DataPanel>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <DataPanel title="Line Items">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-slate-500">
                                    <th className="pb-2 font-medium">Description</th>
                                    <th className="pb-2 text-right font-medium">Qty</th>
                                    <th className="pb-2 text-right font-medium">Unit Cost</th>
                                    <th className="pb-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(requisition.items ?? []).map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-2 text-slate-900">{item.description}</td>
                                        <td className="py-2 text-right text-slate-600">
                                            {item.quantity}
                                        </td>
                                        <td className="py-2 text-right text-slate-600">
                                            {formatCurrency(item.unit_cost)}
                                        </td>
                                        <td className="py-2 text-right font-medium text-slate-900">
                                            {formatCurrency(item.line_total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={3} className="pt-3 text-right font-medium">
                                        Total
                                    </td>
                                    <td className="pt-3 text-right font-bold text-slate-900">
                                        {formatCurrency(amount)}
                                    </td>
                                </tr>
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
                                        {requisition.boq_item.available_qty}{' '}
                                        {requisition.boq_item.unit}
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            <p className="text-sm text-slate-500">No BOQ item linked.</p>
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
                    <DataPanel title="Submit">
                        <form onSubmit={(e) => transition(e, 'under_review')}>
                            <Button type="submit" disabled={transitionForm.processing}>
                                Submit for Review
                            </Button>
                        </form>
                    </DataPanel>
                )}

                {(status === 'approved' || status === 'amended') && canFulfill && (
                    <DataPanel title="Fulfill">
                        <form onSubmit={(e) => transition(e, 'fulfilled')} className="space-y-4">
                            {isStockFulfillment ? (
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
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Payee</Label>
                                        <Input
                                            value={transitionForm.data.payee}
                                            onChange={(e) =>
                                                transitionForm.setData('payee', e.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Method</Label>
                                        <Input
                                            value={transitionForm.data.method}
                                            onChange={(e) =>
                                                transitionForm.setData('method', e.target.value)
                                            }
                                        />
                                    </div>
                                </div>
                            )}
                            <Button type="submit" disabled={transitionForm.processing}>
                                Mark Fulfilled
                            </Button>
                        </form>
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
