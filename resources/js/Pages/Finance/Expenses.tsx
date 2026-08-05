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
import { Expense, ListingFilters, PageProps, Paginated, Project } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface ExpenseSummary {
    total_amount: string;
    from_requisitions: string;
    from_ipcs: string;
    manual_amount: string;
    cash_disbursed: string;
    expense_count: number;
    requisition_count: number;
    ipc_count: number;
    manual_count: number;
}

interface FilterOptions {
    projects: Pick<Project, 'id' | 'code' | 'name'>[];
    sub_types: string[];
    recorders: Array<{ id: number; name: string }>;
}

interface ExpensesProps extends PageProps {
    expenses: Paginated<Expense>;
    summary: ExpenseSummary;
    filterOptions: FilterOptions;
    projects: Project[];
    filters: ListingFilters & {
        project_id?: string;
        sub_type?: string;
        category?: string;
        source?: string;
        recorded_by?: string;
    };
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
    return query ? `/finance/expenses/export?${query}` : '/finance/expenses/export';
}

export default function Expenses() {
    const { expenses, summary, filterOptions, projects, filters, auth } =
        usePage<ExpensesProps>().props;
    const rows = expenses.data ?? [];
    const [open, setOpen] = useState(false);
    const [editingExpense, setEditingExpense] = useState<Expense | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } = useForm({
        category: 'direct' as const,
        project_id: '',
        boq_item_id: '',
        amount: '',
        description: '',
        expense_date: new Date().toISOString().split('T')[0],
        method: 'cash',
        payee: '',
        reference_no: '',
    });
    const canUpdate = hasPermission(auth.user, 'budgets', 'update');
    const colCount = canUpdate ? 8 : 7;
    const activeSubType = filters.sub_type || filters.category;

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
            category: 'direct',
            project_id: String(expense.project_id ?? ''),
            boq_item_id: String(expense.boq_item_id ?? ''),
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

    function selectProject(projectId: string) {
        setData({
            ...data,
            project_id: projectId,
        });
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
                `Delete expense of ${formatCurrency(expense.amount)}? The amount will be returned to cash on hand.`,
            )
        ) {
            return;
        }

        router.delete(`/finance/expenses/${expense.id}`);
    }

    return (
        <AppShell title="Direct Expenses">
            <Head title="Direct Expenses" />
            <div className="space-y-6">
                <PageHeader
                    title="Direct Expenses"
                    description="Project expenses paid from cash on hand — including fulfilled project requisitions. Recording an expense reduces the project float and cannot exceed its balance."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <a href={exportHref(filters)} target="_blank" rel="noopener noreferrer">
                                <Button variant="outline">
                                    <Download className="h-4 w-4" />
                                    Export Excel
                                </Button>
                            </a>
                            <Button onClick={openDialog}>
                                <Plus className="mr-2 h-4 w-4" />
                                Create New Expense
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Total Amount
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">
                            {formatCurrency(summary.total_amount)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {summary.expense_count} expense{summary.expense_count === 1 ? '' : 's'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            From Requisitions
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-blue-700">
                            {formatCurrency(summary.from_requisitions)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {summary.requisition_count} fulfilled request
                            {summary.requisition_count === 1 ? '' : 's'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            From IPCs
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-amber-700">
                            {formatCurrency(summary.from_ipcs)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {summary.ipc_count} compliance line
                            {summary.ipc_count === 1 ? '' : 's'}
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
                        <p className="mt-1 text-sm text-slate-500">Drawn from project floats</p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/finance/expenses"
                    filters={{ ...filters, sub_type: activeSubType }}
                    searchPlaceholder="Search description, purpose, project, requisition, recorder…"
                    sortOptions={[
                        { value: 'expense_date', label: 'Expense date' },
                        { value: 'amount', label: 'Amount' },
                        { value: 'sub_type', label: 'Purpose' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={[
                        ...(filterOptions.projects.length > 0
                            ? [
                                  {
                                      key: 'project_id',
                                      label: 'Project',
                                      emptyLabel: 'All projects',
                                      options: filterOptions.projects.map((p) => ({
                                          value: String(p.id),
                                          label: `${p.code} — ${p.name}`,
                                      })),
                                  },
                              ]
                            : []),
                        {
                            key: 'source',
                            label: 'Source',
                            emptyLabel: 'All sources',
                            options: [
                                { value: 'requisition', label: 'From requisition' },
                                { value: 'ipc', label: 'From IPC' },
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

                <DataPanel title="Direct Expense Ledger" noPadding>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[960px] text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Project</th>
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
                                            No expenses found.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((exp) => {
                                        const payment = paymentSummary(exp);

                                        return (
                                            <tr key={exp.id} className="align-top">
                                                <td className="px-4 py-3 whitespace-nowrap text-slate-700">
                                                    {formatDate(exp.expense_date)}
                                                    {exp.recorder?.name && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            by {exp.recorder.name}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="font-mono text-xs text-slate-500">
                                                        {exp.project?.code ?? '—'}
                                                    </div>
                                                    <div className="font-medium text-slate-800">
                                                        {exp.project?.name ?? '—'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-slate-800">
                                                        {exp.sub_type || 'Administrative'}
                                                    </div>
                                                    {exp.activity_ref && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            Ref {exp.activity_ref}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 max-w-xs">
                                                    <div className="text-slate-700">
                                                        {exp.description ?? '—'}
                                                    </div>
                                                    {exp.boq_item && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            BOQ: {exp.boq_item.description}
                                                            {exp.boq_item.unit
                                                                ? ` (${exp.boq_item.unit})`
                                                                : ''}
                                                        </div>
                                                    )}
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
                                                    ) : exp.valuation ? (
                                                        <Link
                                                            href={`/projects/${exp.valuation.project_id}/valuations/${exp.valuation.id}`}
                                                            className="inline-flex items-center gap-1 font-medium text-amber-700 hover:underline"
                                                        >
                                                            IPC-{exp.valuation.certificate_no}
                                                            <ExternalLink className="h-3 w-3" />
                                                        </Link>
                                                    ) : (
                                                        <span className="text-slate-600">Manual</span>
                                                    )}
                                                    <div className="mt-1 text-xs capitalize text-slate-500">
                                                        {exp.requisition
                                                            ? `Requisition · ${String(exp.requisition.status).replace(/_/g, ' ')}`
                                                            : exp.valuation
                                                              ? 'IPC compliance'
                                                              : 'Posted in finance'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="capitalize text-slate-800">
                                                        {exp.valuation_id ? '—' : payment.method}
                                                    </div>
                                                    {!exp.valuation_id ? (
                                                        <>
                                                            <div className="mt-1 text-xs text-slate-500">
                                                                Payee: {payment.payee}
                                                            </div>
                                                            <div className="text-xs text-slate-500">
                                                                Receipt: {payment.reference}
                                                                {payment.splits > 1
                                                                    ? ` · ${payment.splits} floats`
                                                                    : ''}
                                                            </div>
                                                        </>
                                                    ) : (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            Contract deduction (no cash)
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold text-slate-900 whitespace-nowrap">
                                                    {formatCurrency(exp.amount)}
                                                </td>
                                                {canUpdate && (
                                                    <td className="px-4 py-3">
                                                        {exp.valuation_id ? (
                                                            <span className="text-xs text-slate-400">
                                                                Via valuations
                                                            </span>
                                                        ) : (
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Edit expense"
                                                                    aria-label="Edit expense"
                                                                    onClick={() => editExpense(exp)}
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                                                    title="Delete expense"
                                                                    aria-label="Delete expense"
                                                                    onClick={() => deleteExpense(exp)}
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </div>
                                                        )}
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
                title={editingExpense ? 'Edit Expense' : 'Create New Expense'}
                description={
                    editingExpense
                        ? 'Adjust the expense and its cash-on-hand effect.'
                        : 'Post a direct project expense from total cash on hand.'
                }
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="expense-project">Project</Label>
                        <select
                            id="expense-project"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.project_id}
                            onChange={(e) => selectProject(e.target.value)}
                            required
                        >
                            <option value="">Select project</option>
                            {projects.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.code} — {p.name}
                                </option>
                            ))}
                        </select>
                        {errors.project_id && (
                            <p className="text-sm text-red-600">{errors.project_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="expense-method">Payment method</Label>
                        <PaymentMethodSelect
                            id="expense-method"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                        />
                        {errors.method && <p className="text-sm text-red-600">{errors.method}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="expense-description">Description</Label>
                        <Input
                            id="expense-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="expense-amount">Amount (TZS)</Label>
                        <AmountInput
                            id="expense-amount"
                            value={data.amount}
                            onValueChange={(v) => setData('amount', v)}
                            required
                        />
                        {errors.amount && <p className="text-sm text-red-600">{errors.amount}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="expense-payee">Payee</Label>
                            <Input
                                id="expense-payee"
                                value={data.payee}
                                onChange={(e) => setData('payee', e.target.value)}
                            />
                            {errors.payee && <p className="text-sm text-red-600">{errors.payee}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="expense-reference">Receipt / reference</Label>
                            <Input
                                id="expense-reference"
                                value={data.reference_no}
                                onChange={(e) => setData('reference_no', e.target.value)}
                            />
                            {errors.reference_no && (
                                <p className="text-sm text-red-600">{errors.reference_no}</p>
                            )}
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="expense-date">Expense Date</Label>
                        <Input
                            id="expense-date"
                            type="date"
                            value={data.expense_date}
                            onChange={(e) => setData('expense_date', e.target.value)}
                            required
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel={editingExpense ? 'Save Changes' : 'Post Expense'}
                        processingLabel={editingExpense ? 'Saving…' : 'Posting…'}
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
