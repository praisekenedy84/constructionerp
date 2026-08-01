import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PaymentMethodSelect } from '@/Components/ui/payment-method-select';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { Expense, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface ExpenseSummary {
    total_amount: string;
    from_requisitions: string;
    manual_amount: string;
    cash_disbursed: string;
    expense_count: number;
    requisition_count: number;
    manual_count: number;
}

interface FilterOptions {
    sub_types: string[];
    recorders: Array<{ id: number; name: string }>;
}

interface OverheadProps extends PageProps {
    expenses: Paginated<Expense>;
    summary: ExpenseSummary;
    filters: ListingFilters & {
        sub_type?: string;
        source?: string;
        recorded_by?: string;
    };
    total_overhead: string;
    organization_cash: {
        cash_on_hand: string;
        received: string;
        utilized: string;
    };
    purpose_options: string[];
    filterOptions: FilterOptions;
}

function paymentSummary(expense: Expense): {
    method: string;
    payee: string;
    reference: string;
    splits: number;
} {
    const disbursements = expense.cash_disbursements ?? [];
    const first = disbursements[0];

    return {
        method: first?.method ? first.method.replace(/_/g, ' ') : '—',
        payee: first?.payee || first?.account_name || '—',
        reference: first?.reference_no || '—',
        splits: disbursements.length,
    };
}

function exportHref(filters: Record<string, string | undefined>): string {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    const query = params.toString();
    return query ? `/finance/overhead/export?${query}` : '/finance/overhead/export';
}

export default function Overhead() {
    const {
        expenses,
        summary,
        filters,
        total_overhead,
        organization_cash,
        purpose_options,
        filterOptions,
        auth,
    } = usePage<OverheadProps>().props;
    const rows = expenses.data ?? [];
    const [open, setOpen] = useState(false);
    const [editingExpense, setEditingExpense] = useState<Expense | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } = useForm({
        category: 'indirect' as const,
        sub_type: 'General',
        amount: '',
        description: '',
        expense_date: new Date().toISOString().split('T')[0],
        method: 'cash',
        payee: '',
        reference_no: '',
    });
    const canUpdate = hasPermission(auth.user, 'budgets', 'update');
    const colCount = canUpdate ? 7 : 6;

    // Editing releases the overhead's own payment back to the wallet first,
    // so its already-paid amount is spendable again.
    const paidForEditing = (editingExpense?.cash_disbursements ?? []).reduce(
        (total, disbursement) => total + (parseFloat(disbursement.amount) || 0),
        0,
    );
    const availableCash = (parseFloat(organization_cash.cash_on_hand) || 0) + paidForEditing;
    const enteredAmount = parseFloat(data.amount) || 0;
    const exceedsAvailable = enteredAmount > availableCash;

    function openDialog() {
        setEditingExpense(null);
        reset();
        clearErrors();
        setOpen(true);
    }

    function editExpense(expense: Expense) {
        const disbursement = expense.cash_disbursements?.[0];
        setEditingExpense(expense);
        clearErrors();
        setData({
            category: 'indirect',
            sub_type: expense.sub_type || 'General',
            amount: expense.amount,
            description: expense.description ?? '',
            expense_date: expense.expense_date.slice(0, 10),
            method: disbursement?.method ?? 'cash',
            payee: disbursement?.payee ?? '',
            reference_no: disbursement?.reference_no ?? '',
        });
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        setEditingExpense(null);
        reset();
        clearErrors();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const options = {
            onSuccess: () => {
                reset();
                setEditingExpense(null);
                setOpen(false);
            },
        };

        if (editingExpense) {
            put(`/finance/expenses/${editingExpense.id}`, options);
        } else {
            post('/finance/expenses', options);
        }
    }

    function deleteExpense(expense: Expense) {
        if (
            !confirm(
                `Delete overhead expense of ${formatCurrency(expense.amount)}? Any cash payment will be returned to organization cash on hand.`,
            )
        ) {
            return;
        }

        router.delete(`/finance/expenses/${expense.id}`);
    }

    return (
        <AppShell title="Overhead">
            <Head title="Overhead Expenses" />
            <div className="space-y-6">
                <PageHeader
                    title="Overhead Expenses"
                    description="Indirect company costs paid from organization cash on hand — including fulfilled organization requisitions."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <a href={exportHref(filters)} target="_blank" rel="noopener noreferrer">
                                <Button variant="outline">
                                    <Download className="h-4 w-4" />
                                    Export Excel
                                </Button>
                            </a>
                            <Link href="/finance/organization-cash">
                                <Button variant="outline">Organization Cash</Button>
                            </Link>
                            <Button onClick={openDialog}>
                                <Plus className="mr-2 h-4 w-4" />
                                Record Overhead
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <DataPanel title="Organization Cash on Hand">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(organization_cash.cash_on_hand)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Org Funds Received">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(organization_cash.received)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Total Overhead (filtered)">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(total_overhead)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {summary.expense_count} ledger entr
                            {summary.expense_count === 1 ? 'y' : 'ies'}
                        </p>
                    </DataPanel>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            From Requisitions
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-blue-700">
                            {formatCurrency(summary.from_requisitions)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {summary.requisition_count} request
                            {summary.requisition_count === 1 ? '' : 's'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Manual Posts
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">
                            {formatCurrency(summary.manual_amount)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {summary.manual_count} finance entr
                            {summary.manual_count === 1 ? 'y' : 'ies'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Cash Disbursed
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-emerald-700">
                            {formatCurrency(summary.cash_disbursed)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">Drawn from organization float</p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/finance/overhead"
                    filters={filters}
                    searchPlaceholder="Search description, purpose, requisition, recorder…"
                    sortOptions={[
                        { value: 'expense_date', label: 'Expense date' },
                        { value: 'amount', label: 'Amount' },
                        { value: 'sub_type', label: 'Purpose' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={[
                        {
                            key: 'source',
                            label: 'Source',
                            emptyLabel: 'All sources',
                            options: [
                                { value: 'requisition', label: 'From requisition' },
                                { value: 'manual', label: 'Manual' },
                            ],
                        },
                        ...(filterOptions.sub_types.length > 0
                            ? [
                                  {
                                      key: 'sub_type',
                                      label: 'Purpose',
                                      emptyLabel: 'All purposes',
                                      options: filterOptions.sub_types.map((type) => ({
                                          value: type,
                                          label: type,
                                      })),
                                  },
                              ]
                            : []),
                        ...(filterOptions.recorders.length > 0
                            ? [
                                  {
                                      key: 'recorded_by',
                                      label: 'Recorded by',
                                      emptyLabel: 'Anyone',
                                      options: filterOptions.recorders.map((user) => ({
                                          value: String(user.id),
                                          label: user.name,
                                      })),
                                  },
                              ]
                            : []),
                    ]}
                />

                <DataPanel title="Overhead Ledger" noPadding>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Purpose</th>
                                    <th className="px-4 py-3 font-medium">Details</th>
                                    <th className="px-4 py-3 font-medium">Source</th>
                                    <th className="px-4 py-3 font-medium">Payment</th>
                                    <th className="px-4 py-3 text-right font-medium">Amount</th>
                                    {canUpdate && (
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={colCount}
                                            className="px-6 py-12 text-center text-slate-500"
                                        >
                                            No overhead expenses found.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((exp) => {
                                        const payment = paymentSummary(exp);

                                        return (
                                            <tr key={exp.id} className="align-top">
                                                <td className="whitespace-nowrap px-4 py-3 text-slate-700">
                                                    {formatDate(exp.expense_date)}
                                                    {exp.recorder?.name && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            by {exp.recorder.name}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-slate-800">
                                                        {exp.sub_type || 'General'}
                                                    </div>
                                                    {exp.activity_ref && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            Ref {exp.activity_ref}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="max-w-xs px-4 py-3 text-slate-700">
                                                    {exp.description ?? '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {exp.requisition ? (
                                                        <Link
                                                            href={`/requisitions/${exp.requisition.id}`}
                                                            className="inline-flex items-center gap-1 font-medium text-blue-700 hover:underline"
                                                        >
                                                            {exp.requisition.requisition_no}
                                                            <ExternalLink className="h-3 w-3" />
                                                        </Link>
                                                    ) : (
                                                        <span className="text-slate-600">Manual</span>
                                                    )}
                                                    <div className="mt-1 text-xs capitalize text-slate-500">
                                                        {exp.requisition
                                                            ? `Requisition · ${String(exp.requisition.status).replace(/_/g, ' ')}`
                                                            : 'Posted in finance'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="capitalize text-slate-800">
                                                        {payment.method}
                                                    </div>
                                                    <div className="mt-1 text-xs text-slate-500">
                                                        Payee: {payment.payee}
                                                    </div>
                                                    <div className="text-xs text-slate-500">
                                                        Receipt: {payment.reference}
                                                        {payment.splits > 1
                                                            ? ` · ${payment.splits} floats`
                                                            : ''}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-900">
                                                    {formatCurrency(exp.amount)}
                                                </td>
                                                {canUpdate && (
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                title="Edit overhead expense"
                                                                aria-label="Edit overhead expense"
                                                                onClick={() => editExpense(exp)}
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                                                title="Delete overhead expense"
                                                                aria-label="Delete overhead expense"
                                                                onClick={() => deleteExpense(exp)}
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                    <PaginationLinks paginator={expenses} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title={editingExpense ? 'Edit Overhead Expense' : 'Record Overhead Expense'}
                description={
                    editingExpense
                        ? 'Adjust the overhead and its organization cash effect.'
                        : 'Pay an indirect expense from organization cash on hand.'
                }
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800/60">
                        <div className="flex items-center justify-between">
                            <span className="text-slate-600 dark:text-slate-300">
                                Available organization cash
                            </span>
                            <span className="font-semibold text-green-700">
                                {formatCurrency(availableCash.toFixed(2))}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Overhead cannot exceed the funds finance received from the manager.
                        </p>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-purpose">Purpose</Label>
                        <select
                            id="overhead-purpose"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.sub_type}
                            onChange={(e) => setData('sub_type', e.target.value)}
                            required
                        >
                            {purpose_options.map((option) => (
                                <option key={option} value={option}>
                                    {option}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-method">Payment method</Label>
                        <PaymentMethodSelect
                            id="overhead-method"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                        />
                        {errors.method && <p className="text-sm text-red-600">{errors.method}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-description">Description</Label>
                        <Input
                            id="overhead-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-amount">Amount (TZS)</Label>
                        <AmountInput
                            id="overhead-amount"
                            value={data.amount}
                            onValueChange={(v) => setData('amount', v)}
                            required
                        />
                        {errors.amount && <p className="text-sm text-red-600">{errors.amount}</p>}
                        {!errors.amount && exceedsAvailable && (
                            <p className="text-sm text-red-600">
                                Exceeds available organization cash of{' '}
                                {formatCurrency(availableCash.toFixed(2))}. Request more
                                organization funds or reduce the amount.
                            </p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-date">Date</Label>
                        <Input
                            id="overhead-date"
                            type="date"
                            value={data.expense_date}
                            onChange={(e) => setData('expense_date', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="overhead-payee">Payee</Label>
                            <Input
                                id="overhead-payee"
                                value={data.payee}
                                onChange={(e) => setData('payee', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="overhead-reference">Receipt / reference</Label>
                            <Input
                                id="overhead-reference"
                                value={data.reference_no}
                                onChange={(e) => setData('reference_no', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel={editingExpense ? 'Save Changes' : 'Record Overhead'}
                        processingLabel="Saving…"
                        disabled={exceedsAvailable}
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
