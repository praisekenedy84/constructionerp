import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { RequisitionItem, Unit } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo } from 'react';

export type AmendLine = {
    id?: number | null;
    description: string;
    unit: string;
    quantity: string;
    days: string;
    unit_cost: string;
};

type AmendFormProps = {
    items: RequisitionItem[];
    originalAmount: string;
    resolveUrl: string;
    showOverride?: boolean;
    units?: Unit[];
    onSuccess?: () => void;
};

function daysMultiplier(days: string): number {
    const n = parseFloat(days);
    return Number.isFinite(n) && n > 0 ? n : 1;
}

function lineTotal(quantity: string, unitCost: string, days: string): number {
    return (parseFloat(quantity) || 0) * (parseFloat(unitCost) || 0) * daysMultiplier(days);
}

function daysFromItem(item: RequisitionItem): string {
    const raw = item.details?.days;
    if (raw == null || raw === '') {
        return '';
    }
    const n = parseFloat(String(raw));
    return Number.isFinite(n) && n > 0 ? String(n) : '';
}

function toAmendLines(items: RequisitionItem[]): AmendLine[] {
    return items.map((item) => ({
        id: item.id,
        description: item.description,
        unit: item.unit ?? '',
        quantity: String(item.quantity ?? ''),
        days: daysFromItem(item),
        unit_cost: String(item.unit_cost ?? ''),
    }));
}

export default function AmendRequisitionForm({
    items,
    originalAmount,
    resolveUrl,
    showOverride = false,
    units = [],
    onSuccess,
}: AmendFormProps) {
    const form = useForm({
        action: 'amended' as const,
        amendment_reason: '',
        comment: '',
        override: false,
        items: toAmendLines(items),
    });

    useEffect(() => {
        form.setData('items', toAmendLines(items));
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset lines when selecting another requisition
    }, [items.map((i) => i.id).join(',')]);

    const amendedTotal = useMemo(
        () =>
            form.data.items.reduce(
                (sum, item) => sum + lineTotal(item.quantity, item.unit_cost, item.days),
                0,
            ),
        [form.data.items],
    );

    const original = parseFloat(originalAmount) || 0;
    const variance = original - amendedTotal;

    function updateLine(index: number, patch: Partial<AmendLine>) {
        form.setData(
            'items',
            form.data.items.map((item, i) => (i === index ? { ...item, ...patch } : item)),
        );
    }

    function addLine() {
        form.setData('items', [
            ...form.data.items,
            { id: null, description: '', unit: '', quantity: '', days: '', unit_cost: '' },
        ]);
    }

    function removeLine(index: number) {
        if (form.data.items.length <= 1) {
            return;
        }
        form.setData(
            'items',
            form.data.items.filter((_, i) => i !== index),
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(resolveUrl, { onSuccess });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="space-y-3">
                {form.data.items.map((item, index) => {
                    const total = lineTotal(item.quantity, item.unit_cost, item.days);
                    return (
                        <div
                            key={item.id ?? `new-${index}`}
                            className="rounded-md border border-slate-200 p-3 space-y-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex-1 space-y-2">
                                    <Label>Description</Label>
                                    <Input
                                        value={item.description}
                                        onChange={(e) =>
                                            updateLine(index, { description: e.target.value })
                                        }
                                        required
                                    />
                                </div>
                                {form.data.items.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="mt-7 text-red-700"
                                        onClick={() => removeLine(index)}
                                    >
                                        Remove
                                    </Button>
                                )}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-5">
                                <div className="space-y-2">
                                    <Label>Unit</Label>
                                    <select
                                        className="flex h-10 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                                        value={item.unit}
                                        onChange={(e) => updateLine(index, { unit: e.target.value })}
                                    >
                                        <option value="">Select unit</option>
                                        {units.length === 0 && (
                                            <option value="" disabled>
                                                No units defined
                                            </option>
                                        )}
                                        {units.map((unit) => (
                                            <option key={unit.id ?? unit.name} value={unit.name}>
                                                {unit.name}
                                            </option>
                                        ))}
                                        {item.unit &&
                                            !units.some(
                                                (unit) =>
                                                    unit.name.toLowerCase() ===
                                                    item.unit.toLowerCase(),
                                            ) && (
                                                <option value={item.unit}>{item.unit}</option>
                                            )}
                                    </select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Qty</Label>
                                    <AmountInput
                                        value={item.quantity}
                                        onValueChange={(v) => updateLine(index, { quantity: v })}
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Days (optional)</Label>
                                    <Input
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        placeholder="e.g. 3"
                                        value={item.days}
                                        onChange={(e) => updateLine(index, { days: e.target.value })}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Unit cost</Label>
                                    <AmountInput
                                        value={item.unit_cost}
                                        onValueChange={(v) => updateLine(index, { unit_cost: v })}
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Line total</Label>
                                    <p className="flex h-10 items-center text-sm font-medium text-slate-900">
                                        {formatCurrency(total)}
                                    </p>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            <Button type="button" variant="outline" onClick={addLine}>
                + Add line
            </Button>

            <div className="grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-3">
                <div>
                    <p className="text-xs text-slate-500">Original total</p>
                    <p className="font-medium text-slate-900">{formatCurrency(original)}</p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Amended total</p>
                    <p className="font-medium text-slate-900">{formatCurrency(amendedTotal)}</p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Difference</p>
                    <p
                        className={`font-medium ${
                            variance > 0
                                ? 'text-green-700'
                                : variance < 0
                                  ? 'text-amber-700'
                                  : 'text-slate-900'
                        }`}
                    >
                        {variance > 0 ? '−' : variance < 0 ? '+' : ''}
                        {formatCurrency(Math.abs(variance))}
                        {variance > 0 ? ' (reduced)' : variance < 0 ? ' (increased)' : ''}
                    </p>
                </div>
            </div>

            <div className="space-y-2">
                <Label>Amendment reason</Label>
                <Input
                    value={form.data.amendment_reason}
                    onChange={(e) => form.setData('amendment_reason', e.target.value)}
                    required
                />
                {form.errors.amendment_reason && (
                    <p className="text-sm text-red-600">{form.errors.amendment_reason}</p>
                )}
            </div>

            {showOverride && (
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.override}
                        onChange={(e) => form.setData('override', e.target.checked)}
                    />
                    Override BOQ / cash limits
                </label>
            )}

            {form.errors.items && <p className="text-sm text-red-600">{form.errors.items}</p>}

            <Button
                type="submit"
                variant="outline"
                disabled={form.processing}
                className="border-amber-300 text-amber-800 hover:bg-amber-50"
            >
                Amend with these lines
            </Button>
        </form>
    );
}
