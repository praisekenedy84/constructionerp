import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Expense, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface OverheadProps extends PageProps {
    expenses: Paginated<Expense>;
    filters: ListingFilters & { sub_type?: string };
    total_overhead: string;
}

export default function Overhead() {
    const { expenses, filters, total_overhead } = usePage<OverheadProps>().props;
    const rows = expenses.data ?? [];
    const { data, setData, post, processing, errors, reset } = useForm({
        category: 'indirect' as const,
        sub_type: '',
        amount: '',
        description: '',
        expense_date: new Date().toISOString().split('T')[0],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/finance/expenses', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Overhead">
            <Head title="Overhead Expenses" />
            <div className="space-y-6">
                <PageHeader
                    title="Overhead Expenses"
                    description="Indirect company expenses — no budget transaction, no project."
                />

                <DataPanel title="Total Overhead">
                    <p className="text-3xl font-bold text-slate-900">
                        {formatCurrency(total_overhead)}
                    </p>
                </DataPanel>

                <DataPanel title="Record Overhead Expense">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Sub Type</Label>
                            <Input
                                value={data.sub_type}
                                onChange={(e) => setData('sub_type', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Amount (TZS)</Label>
                            <Input
                                type="number"
                                step="0.01"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                required
                            />
                            {errors.amount && (
                                <p className="text-sm text-red-600">{errors.amount}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Date</Label>
                            <Input
                                type="date"
                                value={data.expense_date}
                                onChange={(e) => setData('expense_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Description</Label>
                            <Input
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={processing}>
                                Record Overhead
                            </Button>
                        </div>
                    </form>
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
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-12 text-center text-slate-500">
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
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={expenses} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
