import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

export type ComplianceCalculationType = 'rate_percent' | 'fixed_amount';

export interface AvailableComplianceRule {
    id: number;
    name: string;
    description: string | null;
}

export interface ComplianceItemForm {
    compliance_rule_id: string;
    calculation_type: ComplianceCalculationType;
    rate: string;
    fixed_amount: string;
}

interface ComplianceItemsEditorProps {
    items: ComplianceItemForm[];
    availableRules: AvailableComplianceRule[];
    /** Project contract amount — rate % is calculated against this. */
    contractAmount: string;
    /** Sum of compliance from other IPCs (excluded from this form). */
    otherIpcsTotal?: string | number;
    ipcLabel?: string;
    /** Prefix for nested Laravel error keys, e.g. `ipcs.0`. */
    errorPrefix?: string;
    /** `full` shows contract + net project; `ipc-only` shows this IPC total. */
    summaryMode?: 'full' | 'ipc-only';
    /** Hide the inner "Compliance Rules" heading when embedded in a parent section. */
    hideHeader?: boolean;
    errors?: Record<string, string>;
    onChange: (items: ComplianceItemForm[]) => void;
}

function parseNumber(value: string | number | null | undefined): number {
    if (value === null || value === undefined || value === '') {
        return 0;
    }
    const num = typeof value === 'number' ? value : parseFloat(value);
    return Number.isNaN(num) ? 0 : num;
}

export function emptyComplianceItem(): ComplianceItemForm {
    return {
        compliance_rule_id: '',
        calculation_type: 'rate_percent',
        rate: '',
        fixed_amount: '',
    };
}

export function computeItemAmount(item: ComplianceItemForm, contract: number): number {
    if (item.calculation_type === 'fixed_amount') {
        return Math.max(parseNumber(item.fixed_amount), 0);
    }
    const rate = parseNumber(item.rate);
    return rate > 0 ? (contract * rate) / 100 : 0;
}

export default function ComplianceItemsEditor({
    items,
    availableRules,
    contractAmount,
    otherIpcsTotal = 0,
    ipcLabel = 'This IPC',
    errorPrefix = '',
    summaryMode = 'full',
    hideHeader = false,
    errors = {},
    onChange,
}: ComplianceItemsEditorProps) {
    const contract = parseNumber(contractAmount);
    const otherTotal = parseNumber(otherIpcsTotal);
    const itemErrorKey = (index: number, field: string) =>
        errorPrefix
            ? `${errorPrefix}.compliance_items.${index}.${field}`
            : `compliance_items.${index}.${field}`;
    const itemsErrorKey = errorPrefix ? `${errorPrefix}.compliance_items` : 'compliance_items';

    function updateItem(index: number, patch: Partial<ComplianceItemForm>) {
        onChange(items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    }

    function removeItem(index: number) {
        onChange(items.filter((_, i) => i !== index));
    }

    function addItem() {
        onChange([...items, emptyComplianceItem()]);
    }

    function ruleName(ruleId: string): string {
        const id = parseInt(ruleId, 10);
        return availableRules.find((r) => r.id === id)?.name ?? `Rule ${ruleId}`;
    }

    function optionsForRow(index: number): AvailableComplianceRule[] {
        const selectedElsewhere = new Set(
            items
                .map((item, i) => (i === index ? null : item.compliance_rule_id))
                .filter((id): id is string => !!id),
        );

        return availableRules.filter((rule) => {
            const id = String(rule.id);
            const current = items[index]?.compliance_rule_id;
            return id === current || !selectedElsewhere.has(id);
        });
    }

    const lines = items.map((item, index) => ({
        index,
        item,
        amount: computeItemAmount(item, contract),
    }));
    const ipcTotal = lines.reduce((sum, line) => sum + line.amount, 0);
    const allCompliance = otherTotal + ipcTotal;
    const netProject = Math.max(contract - allCompliance, 0);

    if (availableRules.length === 0) {
        return (
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-6 text-sm text-amber-900">
                <p className="font-medium">No compliance rules defined yet.</p>
                <p className="mt-1">
                    Create rules under{' '}
                    <Link href="/projects/compliance-rules" className="underline">
                        Projects → Compliance Rules
                    </Link>{' '}
                    before adding them to an IPC.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {!hideHeader && (
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-900">Compliance Rules</h3>
                        <p className="text-xs text-slate-500">
                            Select a predefined rule, then choose rate % of contract or a fixed
                            amount.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addItem}
                        disabled={items.length >= availableRules.length}
                    >
                        <Plus className="h-4 w-4" />
                        Add rule
                    </Button>
                </div>
            )}
            {hideHeader && (
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addItem}
                        disabled={items.length >= availableRules.length}
                    >
                        <Plus className="h-4 w-4" />
                        Add rule
                    </Button>
                </div>
            )}

            <div className="space-y-3">
                {items.map((item, index) => {
                    const amount = computeItemAmount(item, contract);
                    const options = optionsForRow(index);

                    return (
                        <div
                            key={index}
                            className="space-y-3 rounded-lg border border-slate-200 bg-white p-4"
                        >
                            <div className="flex flex-wrap items-start gap-3">
                                <div className="min-w-[14rem] flex-1 space-y-1.5">
                                    <Label>Compliance rule</Label>
                                    <select
                                        className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                                        value={item.compliance_rule_id}
                                        onChange={(e) =>
                                            updateItem(index, {
                                                compliance_rule_id: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">Select rule…</option>
                                        {options.map((rule) => (
                                            <option key={rule.id} value={rule.id}>
                                                {rule.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors[itemErrorKey(index, 'compliance_rule_id')] && (
                                        <p className="text-sm text-red-600">
                                            {errors[itemErrorKey(index, 'compliance_rule_id')]}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Type</Label>
                                    <select
                                        className="flex h-10 w-40 rounded-md border border-slate-200 bg-white px-3 text-sm"
                                        value={item.calculation_type}
                                        onChange={(e) =>
                                            updateItem(index, {
                                                calculation_type: e.target
                                                    .value as ComplianceCalculationType,
                                                rate:
                                                    e.target.value === 'rate_percent'
                                                        ? item.rate
                                                        : '',
                                                fixed_amount:
                                                    e.target.value === 'fixed_amount'
                                                        ? item.fixed_amount
                                                        : '',
                                            })
                                        }
                                    >
                                        <option value="rate_percent">Rate %</option>
                                        <option value="fixed_amount">Fixed amount</option>
                                    </select>
                                </div>
                                {item.calculation_type === 'rate_percent' ? (
                                    <div className="space-y-1.5">
                                        <Label>Rate % of contract</Label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            className="w-28"
                                            placeholder="10"
                                            value={item.rate}
                                            onChange={(e) =>
                                                updateItem(index, { rate: e.target.value })
                                            }
                                        />
                                        {errors[itemErrorKey(index, 'rate')] && (
                                            <p className="text-sm text-red-600">
                                                {errors[itemErrorKey(index, 'rate')]}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-1.5">
                                        <Label>Fixed amount</Label>
                                        <AmountInput
                                            className="w-40"
                                            value={item.fixed_amount}
                                            onValueChange={(v) =>
                                                updateItem(index, { fixed_amount: v })
                                            }
                                        />
                                        {errors[itemErrorKey(index, 'fixed_amount')] && (
                                            <p className="text-sm text-red-600">
                                                {errors[itemErrorKey(index, 'fixed_amount')]}
                                            </p>
                                        )}
                                    </div>
                                )}
                                <div className="ml-auto flex items-end gap-3 pt-6">
                                    {amount > 0 && (
                                        <span className="text-sm font-medium text-red-600">
                                            −{formatCurrency(amount)}
                                        </span>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeItem(index)}
                                        aria-label="Remove compliance rule"
                                        disabled={items.length <= 1}
                                    >
                                        <Trash2 className="h-4 w-4 text-slate-500" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {errors[itemsErrorKey] && (
                <p className="text-sm text-red-600">{errors[itemsErrorKey]}</p>
            )}

            <dl className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                {summaryMode === 'full' && (
                    <div className="flex justify-between gap-4">
                        <dt className="text-slate-600">Contract amount</dt>
                        <dd className="font-medium">{formatCurrency(contract || null)}</dd>
                    </div>
                )}
                {lines
                    .filter((line) => line.amount > 0 || line.item.compliance_rule_id)
                    .map((line) => (
                        <div key={line.index} className="flex justify-between gap-4 text-red-700">
                            <dt>
                                − {ruleName(line.item.compliance_rule_id) || `Rule ${line.index + 1}`}
                                {line.item.calculation_type === 'rate_percent' && line.item.rate
                                    ? ` (${line.item.rate}%)`
                                    : ''}
                            </dt>
                            <dd>−{formatCurrency(line.amount)}</dd>
                        </div>
                    ))}
                <div
                    className={`flex justify-between gap-4 ${summaryMode === 'full' ? 'border-t border-slate-200 pt-2' : ''}`}
                >
                    <dt className="font-semibold text-slate-900">
                        Total {ipcLabel} (compliance rules)
                    </dt>
                    <dd className="font-semibold text-red-700">−{formatCurrency(ipcTotal)}</dd>
                </div>
                {summaryMode === 'full' && (
                    <>
                        {otherTotal > 0 && (
                            <div className="flex justify-between gap-4 text-slate-600">
                                <dt>Other IPCs compliance</dt>
                                <dd>−{formatCurrency(otherTotal)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between gap-4 border-t border-slate-200 pt-2">
                            <dt className="font-semibold text-slate-900">Net project amount</dt>
                            <dd className="text-lg font-bold text-slate-900">
                                {formatCurrency(netProject)}
                            </dd>
                        </div>
                        <p className="pt-1 text-xs text-slate-500">
                            Net project amount = Contract − Sum of all IPCs&apos; compliance rules
                        </p>
                    </>
                )}
            </dl>
        </div>
    );
}
