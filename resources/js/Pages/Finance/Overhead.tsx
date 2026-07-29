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
import { formatCurrency, formatDate } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { Expense, ListingFilters, PageProps, Paginated, SpendableCashFloat } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface OverheadProps extends PageProps {
    expenses: Paginated<Expense>;
    filters: ListingFilters & { sub_type?: string };
    total_overhead: string;
    cash_floats: SpendableCashFloat[];
}

export default function Overhead() {
    const { expenses, filters, total_overhead, cash_floats, auth } = usePage<OverheadProps>().props;
    const rows = expenses.data ?? [];
    const orgFloats = cash_floats.filter((float) => float.project_id === null);
    const [open, setOpen] = useState(false);
    const [editingExpense, setEditingExpense] = useState<Expense | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } = useForm({
        category: 'indirect' as const,
        sub_type: '',
        amount: '',
        description: '',
        expense_date: new Date().toISOString().split('T')[0],
        cash_allocation_id: '',
        method: 'cash',
        payee: '',
        reference_no: '',
    });
    const canUpdate = hasPermission(auth.user, 'budgets', 'update');

    function openDialog() {
        setEditingExpense(null);
        reset();
        clearErrors();
        setOpen(true);
    }

    function editExpense(expense: Expense) {
        const disbursement = expense.cash_disbursement;
        setEditingExpense(expense);
        clearErrors();
        setData({
            category: 'indirect',
            sub_type: expense.sub_type,
            amount: expense.amount,
            description: expense.description ?? '',
            expense_date: expense.expense_date.slice(0, 10),
            cash_allocation_id: String(disbursement?.cash_allocation?.id ?? ''),
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
        if (!confirm(`Delete ${expense.sub_type} expense of ${formatCurrency(expense.amount)}? Any cash payment will be returned to cash on hand.`)) {
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
                    description="Indirect company expenses paid from organisation-wide cash on hand."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Record Overhead
                        </Button>
                    }
                />

                <DataPanel title="Total Overhead">
                    <p className="text-3xl font-bold text-slate-900">
                        {formatCurrency(total_overhead)}
                    </p>
                </DataPanel>

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
                                {canUpdate && <th className="px-6 py-3 text-right font-medium">Actions</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={canUpdate ? 5 : 4} className="px-6 py-12 text-center text-slate-500">
                                        No overhead expenses found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((exp) => (
                                    <tr key={exp.id}>
                                        <td className="px-6 py-4">{formatDate(exp.expense_date)}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status="indirect" />
                                            <span className="ml-2 text-slate-600">{exp.sub_type}</span>
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
                description={editingExpense ? 'Adjust the overhead and its cash-on-hand effect.' : 'Pay an indirect expense from organisation-wide cash on hand.'}
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="overhead-float">Organisation cash float</Label>
                        <select
                            id="overhead-float"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.cash_allocation_id}
                            onChange={(e) => setData('cash_allocation_id', e.target.value)}
                            required
                        >
                            <option value="">
                                {orgFloats.length ? 'Select cash float' : 'No organisation cash on hand'}
                            </option>
                            {orgFloats.map((float) => (
                                <option key={float.id} value={float.id}>
                                    {float.reference_no ? `${float.reference_no} · ` : ''}
                                    available {formatCurrency(float.balance)}
                                </option>
                            ))}
                        </select>
                        {errors.cash_allocation_id && (
                            <p className="text-sm text-red-600">{errors.cash_allocation_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-method">Payment method</Label>
                        <select
                            id="overhead-method"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                            required
                        >
                            <option value="cash">Cash</option>
                            <option value="mobile">Mobile money</option>
                            <option value="bank">Bank transfer</option>
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="overhead-sub-type">Sub Type</Label>
                        <Input
                            id="overhead-sub-type"
                            value={data.sub_type}
                            onChange={(e) => setData('sub_type', e.target.value)}
                            required
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
                    <div className="space-y-2">
                        <Label htmlFor="overhead-description">Description</Label>
                        <Input
                            id="overhead-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
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
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
