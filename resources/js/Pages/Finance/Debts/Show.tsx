import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
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
import {
    CompanyDebt,
    CompanyDebtPayment,
    MoneyAccount,
    PageProps,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Banknote } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface DebtsShowProps extends PageProps {
    debt: CompanyDebt;
    payments: CompanyDebtPayment[];
    manager_accounts: MoneyAccount[];
    can_repay: boolean;
}

export default function DebtsShow() {
    const { debt, payments, manager_accounts, can_repay } = usePage<DebtsShowProps>().props;
    const [repayOpen, setRepayOpen] = useState(false);

    const repayForm = useForm({
        amount: debt.outstanding_amount,
        money_account_id: manager_accounts[0]?.id ? String(manager_accounts[0].id) : '',
        method: '',
        reference_no: '',
        notes: '',
        occurred_at: '',
    });

    function openRepayDialog() {
        repayForm.clearErrors();
        repayForm.setData({
            amount: debt.outstanding_amount,
            money_account_id: manager_accounts[0]?.id ? String(manager_accounts[0].id) : '',
            method: '',
            reference_no: '',
            notes: '',
            occurred_at: '',
        });
        setRepayOpen(true);
    }

    function closeRepayDialog() {
        if (!confirmDiscardIfDirty(repayForm.isDirty)) {
            return;
        }
        setRepayOpen(false);
        repayForm.reset();
        repayForm.clearErrors();
    }

    function submitRepayment(e: FormEvent) {
        e.preventDefault();
        repayForm.post(`/finance/debts/${debt.id}/payments`, {
            onSuccess: () => {
                setRepayOpen(false);
                repayForm.reset();
            },
        });
    }

    return (
        <AppShell title={`Debt — ${debt.creditor_name}`}>
            <Head title={`Debt — ${debt.creditor_name}`} />
            <div className="space-y-6">
                <PageHeader
                    title={debt.creditor_name}
                    description={`${debt.type_label} · deposited into ${debt.money_account?.name ?? 'company account'}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/finance/debts">
                                <Button variant="outline" size="sm">
                                    <ArrowLeft className="mr-1 h-3.5 w-3.5" />
                                    Back to debts
                                </Button>
                            </Link>
                            {can_repay && (
                                <Button size="sm" onClick={openRepayDialog}>
                                    <Banknote className="mr-1 h-3.5 w-3.5" />
                                    Record repayment
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Original amount</p>
                        <p className="mt-1 text-xl font-bold text-slate-900">
                            {formatCurrency(debt.original_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Outstanding</p>
                        <p className="mt-1 text-xl font-bold text-slate-900">
                            {formatCurrency(debt.outstanding_amount)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Status</p>
                        <div className="mt-2">
                            <StatusBadge status={debt.status} />
                        </div>
                    </div>
                </div>

                <DataPanel title="Details">
                    <dl className="grid gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <dt className="text-slate-500">Type</dt>
                            <dd className="mt-1 font-medium text-slate-900">{debt.type_label}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Date</dt>
                            <dd className="mt-1 font-medium text-slate-900">
                                {debt.occurred_at ? formatDate(debt.occurred_at) : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Company account</dt>
                            <dd className="mt-1 font-medium text-slate-900">
                                {debt.money_account?.name ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Recorded by</dt>
                            <dd className="mt-1 font-medium text-slate-900">
                                {debt.recorder?.name ?? '—'}
                            </dd>
                        </div>
                        {debt.notes && (
                            <div className="sm:col-span-2">
                                <dt className="text-slate-500">Notes</dt>
                                <dd className="mt-1 text-slate-900">{debt.notes}</dd>
                            </div>
                        )}
                    </dl>
                </DataPanel>

                <DataPanel title={`Payments (${payments.length})`} noPadding>
                    {payments.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No repayments recorded yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                        <th className="px-4 py-3 font-medium">Date</th>
                                        <th className="px-4 py-3 font-medium">Account</th>
                                        <th className="px-4 py-3 text-right font-medium">Amount</th>
                                        <th className="px-4 py-3 font-medium">Method</th>
                                        <th className="px-4 py-3 font-medium">Reference</th>
                                        <th className="px-4 py-3 font-medium">Recorded by</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {payments.map((payment) => (
                                        <tr key={payment.id}>
                                            <td className="px-4 py-3 text-slate-600">
                                                {payment.occurred_at
                                                    ? formatDate(payment.occurred_at)
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-slate-900">
                                                {payment.money_account?.name ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium text-red-700">
                                                −{formatCurrency(payment.amount)}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {payment.method || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {payment.reference_no || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {payment.recorder?.name ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DataPanel>
            </div>

            {can_repay && (
                <Dialog
                    open={repayOpen}
                    onOpenChange={(open) => {
                        if (open) {
                            setRepayOpen(true);
                        } else {
                            closeRepayDialog();
                        }
                    }}
                    title="Record repayment"
                    description={`Outstanding balance: ${formatCurrency(debt.outstanding_amount)}. Payment debits a company account and reduces this debt.`}
                >
                    <form onSubmit={submitRepayment} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Amount (TZS)</Label>
                            <AmountInput
                                value={repayForm.data.amount}
                                onValueChange={(v) => repayForm.setData('amount', v)}
                                required
                            />
                            {repayForm.errors.amount && (
                                <p className="text-sm text-red-600">{repayForm.errors.amount}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="repay-account">Pay from company account</Label>
                            <select
                                id="repay-account"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={repayForm.data.money_account_id}
                                onChange={(e) =>
                                    repayForm.setData('money_account_id', e.target.value)
                                }
                                required
                            >
                                <option value="">Select account…</option>
                                {manager_accounts.map((account) => (
                                    <option key={account.id} value={String(account.id)}>
                                        {account.name} ({formatCurrency(account.balance)})
                                    </option>
                                ))}
                            </select>
                            {repayForm.errors.money_account_id && (
                                <p className="text-sm text-red-600">
                                    {repayForm.errors.money_account_id}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Method</Label>
                            <PaymentMethodSelect
                                value={repayForm.data.method}
                                onChange={(e) => repayForm.setData('method', e.target.value)}
                                optional
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Reference</Label>
                            <Input
                                value={repayForm.data.reference_no}
                                onChange={(e) => repayForm.setData('reference_no', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <Input
                                value={repayForm.data.notes}
                                onChange={(e) => repayForm.setData('notes', e.target.value)}
                            />
                        </div>
                        <DialogFormActions
                            onCancel={closeRepayDialog}
                            processing={repayForm.processing}
                            submitLabel="Record repayment"
                            processingLabel="Saving…"
                        />
                    </form>
                </Dialog>
            )}
        </AppShell>
    );
}
