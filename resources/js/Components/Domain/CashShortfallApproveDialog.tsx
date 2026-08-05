import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { formatCurrency } from '@/lib/formatters';
import { CashAvailability } from '@/types';

interface CashShortfallApproveDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    availability: CashAvailability;
    canAmend: boolean;
    canOverride: boolean;
    overrideChecked: boolean;
    onOverrideChange: (checked: boolean) => void;
    onAmend: () => void;
    onReject: () => void;
    onApproveWithOverride: () => void;
    processing?: boolean;
}

export default function CashShortfallApproveDialog({
    open,
    onOpenChange,
    availability,
    canAmend,
    canOverride,
    overrideChecked,
    onOverrideChange,
    onAmend,
    onReject,
    onApproveWithOverride,
    processing = false,
}: CashShortfallApproveDialogProps) {
    const poolLabel =
        availability.scope === 'organization'
            ? 'administrative cash on hand'
            : 'project cash on hand';
    const shortfall = Math.max(
        0,
        (parseFloat(availability.required) || 0) - (parseFloat(availability.available) || 0),
    );

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Request exceeds cash on hand"
            description={`This requisition is above available ${poolLabel}. Approved requests cannot be amended later, so you must amend the amount down or reject it.`}
        >
            <div className="space-y-4">
                <dl className="grid gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-amber-800">Cash on hand</dt>
                        <dd className="font-semibold text-amber-950">
                            {formatCurrency(availability.cash_on_hand)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-amber-800">Already committed</dt>
                        <dd className="font-semibold text-amber-950">
                            {formatCurrency(availability.committed)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-amber-800">Available to approve</dt>
                        <dd className="font-semibold text-amber-950">
                            {formatCurrency(availability.available)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-amber-800">This request</dt>
                        <dd className="font-semibold text-amber-950">
                            {formatCurrency(availability.required)}
                        </dd>
                    </div>
                    <div className="sm:col-span-2">
                        <dt className="text-xs text-amber-800">Shortfall</dt>
                        <dd className="font-semibold text-red-700">
                            {formatCurrency(String(shortfall.toFixed(2)))}
                        </dd>
                    </div>
                </dl>

                <p className="text-sm text-slate-600">
                    Choose <span className="font-medium text-slate-800">Amend</span> to reduce the
                    amount to available cash, or{' '}
                    <span className="font-medium text-slate-800">Reject</span> if it should not
                    proceed.
                </p>

                {canOverride && (
                    <div className="space-y-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                        <label className="flex items-start gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                className="mt-0.5 rounded border-slate-300"
                                checked={overrideChecked}
                                onChange={(e) => onOverrideChange(e.target.checked)}
                            />
                            <span>
                                Override cash limits and approve as-is (use only when funding is
                                confirmed outside this float).
                            </span>
                        </label>
                    </div>
                )}

                <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="border-red-300 text-red-700 hover:bg-red-50"
                        onClick={onReject}
                    >
                        Reject
                    </Button>
                    {canAmend && (
                        <Button type="button" onClick={onAmend}>
                            Amend instead
                        </Button>
                    )}
                    {canOverride && (
                        <Button
                            type="button"
                            disabled={!overrideChecked || processing}
                            className="bg-green-700 hover:bg-green-800"
                            onClick={onApproveWithOverride}
                        >
                            {processing ? 'Approving…' : 'Approve with override'}
                        </Button>
                    )}
                </div>
            </div>
        </Dialog>
    );
}
