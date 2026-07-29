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
import { Expense, ListingFilters, PageProps, Paginated, Project, SpendableCashFloat } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

interface ExpensesProps extends PageProps {
    expenses: Paginated<Expense>;
    filters: ListingFilters & { project_id?: string; category?: string };
    projects: Project[];
    cash_floats: SpendableCashFloat[];
}

export default function Expenses() {
    const { expenses, filters, projects, cash_floats } = usePage<ExpensesProps>().props;
    const rows = expenses.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        category: 'direct' as const,
        project_id: '',
        boq_item_id: '',
        sub_type: '',
        amount: '',
        description: '',
        expense_date: new Date().toISOString().split('T')[0],
        cash_allocation_id: '',
        method: 'cash',
        payee: '',
        reference_no: '',
    });

    const availableFloats = useMemo(() => {
        if (!data.project_id) {
            return [];
        }

        const projectId = Number(data.project_id);

        return cash_floats.filter(
            (float) => float.project_id === null || float.project_id === projectId,
        );
    }, [cash_floats, data.project_id]);

    const selectedFloat = availableFloats.find(
        (float) => String(float.id) === data.cash_allocation_id,
    );

    function openDialog() {
        clearErrors();
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        reset();
        clearErrors();
    }

    function selectProject(projectId: string) {
        setData({
            ...data,
            project_id: projectId,
            cash_allocation_id: '',
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/finance/expenses', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    function floatLabel(float: SpendableCashFloat): string {
        const scope = float.project
            ? `${float.project.code} — ${float.project.name}`
            : 'Organisation-wide';
        const ref = float.reference_no ? ` · ${float.reference_no}` : '';

        return `${scope}${ref} · available ${formatCurrency(float.balance)}`;
    }

    return (
        <AppShell title="Direct Expenses">
            <Head title="Direct Expenses" />
            <div className="space-y-6">
                <PageHeader
                    title="Direct Expenses"
                    description="Project expenses paid from cash on hand. Recording an expense reduces the selected float and cannot exceed its balance."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Create New Expense
                        </Button>
                    }
                />

                <ListToolbar
                    baseUrl="/finance/expenses"
                    filters={filters}
                    searchPlaceholder="Search description, sub type, project…"
                    sortOptions={[
                        { value: 'expense_date', label: 'Expense date' },
                        { value: 'amount', label: 'Amount' },
                        { value: 'sub_type', label: 'Sub type' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={
                        projects.length > 0
                            ? [
                                  {
                                      key: 'project_id',
                                      label: 'Project',
                                      emptyLabel: 'All projects',
                                      options: projects.map((p) => ({
                                          value: String(p.id),
                                          label: `${p.code} — ${p.name}`,
                                      })),
                                  },
                              ]
                            : undefined
                    }
                    textFilters={[{ key: 'category', label: 'Sub type', placeholder: 'Sub type' }]}
                />

                <DataPanel title="Direct Expense Ledger" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Project</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 font-medium">Paid from</th>
                                <th className="px-6 py-3 font-medium">Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No expenses found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((exp) => (
                                    <tr key={exp.id}>
                                        <td className="px-6 py-4">{formatDate(exp.expense_date)}</td>
                                        <td className="px-6 py-4">{exp.project?.name ?? '—'}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={exp.category} />
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(exp.amount)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {exp.cash_disbursement
                                                ? [
                                                      exp.cash_disbursement.method,
                                                      exp.cash_disbursement.cash_allocation?.reference_no,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ') || 'Cash float'
                                                : '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {exp.description ?? '—'}
                                        </td>
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
                title="Create New Expense"
                description="Post a direct project expense paid from a cash float."
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
                        <Label htmlFor="expense-float">Cash float</Label>
                        <select
                            id="expense-float"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.cash_allocation_id}
                            onChange={(e) => setData('cash_allocation_id', e.target.value)}
                            required
                            disabled={!data.project_id}
                        >
                            <option value="">
                                {data.project_id
                                    ? availableFloats.length > 0
                                        ? 'Select cash float'
                                        : 'No cash on hand for this project'
                                    : 'Select a project first'}
                            </option>
                            {availableFloats.map((float) => (
                                <option key={float.id} value={float.id}>
                                    {floatLabel(float)}
                                </option>
                            ))}
                        </select>
                        {selectedFloat && (
                            <p className="text-xs text-slate-500">
                                Available on this float: {formatCurrency(selectedFloat.balance)}
                            </p>
                        )}
                        {errors.cash_allocation_id && (
                            <p className="text-sm text-red-600">{errors.cash_allocation_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="expense-method">Payment method</Label>
                        <select
                            id="expense-method"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                            required
                        >
                            <option value="cash">Cash</option>
                            <option value="mobile">Mobile money</option>
                            <option value="bank">Bank transfer</option>
                        </select>
                        {errors.method && <p className="text-sm text-red-600">{errors.method}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="expense-sub-type">Sub Type</Label>
                        <Input
                            id="expense-sub-type"
                            value={data.sub_type}
                            onChange={(e) => setData('sub_type', e.target.value)}
                            required
                        />
                        {errors.sub_type && <p className="text-sm text-red-600">{errors.sub_type}</p>}
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
                    <div className="space-y-2">
                        <Label htmlFor="expense-description">Description</Label>
                        <Input
                            id="expense-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Post Expense"
                        processingLabel="Posting…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
