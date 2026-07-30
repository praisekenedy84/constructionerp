import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
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
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface OverheadProps extends PageProps {
    expenses: Paginated<Expense>;
    filters: ListingFilters & { sub_type?: string };
    total_overhead: string;
    organization_cash: {
        cash_on_hand: string;
        received: string;
        utilized: string;
    };
    purpose_options: string[];
}

export default function Overhead() {
    const {
        expenses,
        filters,
        total_overhead,
        organization_cash,
        purpose_options,
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
                    description="Indirect company costs paid only from organization cash on hand — not from project floats."
                    actions={
                        <div className="flex flex-wrap gap-2">
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
                    <DataPanel title="Total Overhead (ledger)">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(total_overhead)}
                        </p>
                    </DataPanel>
                </div>

                <ListToolbar
                    baseUrl="/finance/overhead"
                    filters={filters}
                    searchPlaceholder="Search description, sub type…"
                    sortOptions={[
                        { value: 'expense_date', label: 'Expense date' },
                        { value: 'amount', label: 'Amount' },
                        { value: 'sub_type', label: 'Sub type' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    textFilters={[{ key: 'sub_type', label: 'Sub type', placeholder: 'Sub type' }]}
                />

                <DataPanel title="Overhead Ledger" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 font-medium">Description</th>
                                {canUpdate && (
                                    <th className="px-6 py-3 text-right font-medium">Actions</th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={canUpdate ? 5 : 4}
                                        className="px-6 py-12 text-center text-slate-500"
                                    >
                                        No overhead expenses found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((exp) => (
                                    <tr key={exp.id}>
                                        <td className="px-6 py-4">{formatDate(exp.expense_date)}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status="indirect" />
                                            <span className="ml-2 text-slate-600">
                                                {exp.sub_type}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(exp.amount)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {exp.description ?? '—'}
                                        </td>
                                        {canUpdate && (
                                            <td className="px-6 py-4">
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
                                ))
                            )}
                        </tbody>
                    </table>
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
