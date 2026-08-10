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
import { hasPermission } from '@/lib/permissions';
import {
    MoneyAccount,
    PageProps,
    Sale,
    SaleReceivablePayment,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Banknote, RefreshCw } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface SalesShowProps extends PageProps {
    sale: Sale;
    payments: SaleReceivablePayment[];
    manager_accounts: MoneyAccount[];
}

export default function SalesShow() {
    const { sale, payments, manager_accounts, auth, errors } =
        usePage<SalesShowProps>().props;
    const canConvert = hasPermission(auth.user, 'sales', 'convert');
    const canCollect = hasPermission(auth.user, 'sales', 'collect');
    const [collectOpen, setCollectOpen] = useState(false);

    const {
        data,
        setData,
        post,
        processing,
        errors: formErrors,
        reset,
        clearErrors,
        isDirty,
    } = useForm({
        amount: sale.outstanding_amount,
        money_account_id: manager_accounts[0]?.id ? String(manager_accounts[0].id) : '',
        method: '',
        reference_no: '',
        notes: '',
        occurred_at: '',
    });

    const isLoss = Boolean(sale.is_loss);
    const collectLabel = isLoss ? 'Record loss' : 'Collect';

    function convertToReceivable() {
        if (
            !confirm(
                `Convert this phase's surplus of ${formatCurrency(sale.profit_amount)} into a receivable for ${sale.sale_code}?`,
            )
        ) {
            return;
        }

        router.post(`/sales/${sale.id}/convert-receivable`);
    }

    function openCollectDialog() {
        clearErrors();
        setData({
            amount: sale.outstanding_amount,
            money_account_id: manager_accounts[0]?.id ? String(manager_accounts[0].id) : '',
            method: '',
            reference_no: '',
            notes: '',
            occurred_at: '',
        });
        setCollectOpen(true);
    }

    function closeCollectDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setCollectOpen(false);
        reset();
        clearErrors();
    }

    function submitCollection(e: FormEvent) {
        e.preventDefault();
        post(`/sales/${sale.id}/collect`, {
            onSuccess: () => {
                setCollectOpen(false);
                reset();
            },
        });
    }

    return (
        <AppShell title={`Sale ${sale.sale_code}`}>
            <Head title={`Sale — ${sale.sale_code}`} />
            <div className="space-y-6">
                <PageHeader
                    title={sale.sale_code}
                    description={`${sale.customer ?? sale.project?.client ?? 'Customer'} · ${
                        sale.project ? `${sale.project.code} — ${sale.project.name}` : 'Project'
                    }${
                        sale.phase
                            ? ` · Phase ${sale.phase.sequence_no}: ${sale.phase.name}`
                            : ''
                    }`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/sales">
                                <Button variant="outline" size="sm">
                                    <ArrowLeft className="h-4 w-4" />
                                    Back
                                </Button>
                            </Link>
                            {sale.project && (
                                <Link href={`/projects/${sale.project.id}`}>
                                    <Button variant="outline" size="sm">
                                        Open project
                                    </Button>
                                </Link>
                            )}
                            {sale.project && sale.phase && (
                                <Link
                                    href={`/projects/${sale.project.id}/phases/${sale.phase.id}`}
                                >
                                    <Button variant="outline" size="sm">
                                        Open phase
                                    </Button>
                                </Link>
                            )}
                            {canConvert && sale.can_convert && (
                                <Button size="sm" onClick={convertToReceivable}>
                                    <RefreshCw className="mr-1 h-4 w-4" />
                                    Convert to Receivable
                                </Button>
                            )}
                            {canCollect && sale.can_collect && (
                                <Button size="sm" onClick={openCollectDialog}>
                                    <Banknote className="mr-1 h-4 w-4" />
                                    {collectLabel}
                                </Button>
                            )}
                        </div>
                    }
                />

                {(errors.convert || errors.amount || errors.loss) && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {errors.convert ?? errors.amount ?? errors.loss}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <DataPanel title="Customer">
                        <p className="text-xl font-semibold text-slate-900">
                            {sale.customer ?? sale.project?.client ?? '—'}
                        </p>
                    </DataPanel>
                    <DataPanel title="Phase Disbursed">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(sale.contract_amount)}
                        </p>
                    </DataPanel>
                    <DataPanel title={isLoss ? 'Loss amount' : 'Profit Share'}>
                        <p
                            className={`text-2xl font-bold ${
                                isLoss ? 'text-red-700' : 'text-green-700'
                            }`}
                        >
                            {formatCurrency(sale.profit_amount)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            {isLoss
                                ? 'Company loss recorded from project deficit'
                                : sale.status === 'open'
                                  ? 'Estimated surplus after carried deficits'
                                  : 'Snapshotted at conversion'}
                        </p>
                    </DataPanel>
                    <DataPanel title="Outstanding">
                        <p className="text-2xl font-bold text-blue-700">
                            {formatCurrency(sale.outstanding_amount)}
                        </p>
                        <div className="mt-2">
                            <StatusBadge status={sale.status} />
                        </div>
                    </DataPanel>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <DataPanel title="Sale summary" className="lg:col-span-1">
                        <dl className="space-y-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Sale ID</dt>
                                <dd className="font-mono text-slate-900">{sale.sale_code}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Phase</dt>
                                <dd className="text-right text-slate-900">
                                    {sale.phase
                                        ? `Phase ${sale.phase.sequence_no}: ${sale.phase.name}`
                                        : 'Legacy (project)'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Phase status</dt>
                                <dd>
                                    {sale.phase ? (
                                        <StatusBadge status={sale.phase.status} />
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Remaining budget</dt>
                                <dd className="text-slate-900">
                                    {formatCurrency(sale.remaining_budget ?? '0')}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Already recognized</dt>
                                <dd className="text-slate-900">
                                    {formatCurrency(sale.recognized_amount ?? '0')}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Phase share</dt>
                                <dd className="text-slate-900">
                                    {sale.status === 'open'
                                        ? `${sale.phase_share_pct ?? '0.00'}%`
                                        : '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Collected</dt>
                                <dd className="text-slate-900">
                                    {formatCurrency(sale.collected_amount)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Converted</dt>
                                <dd className="text-slate-900">
                                    {sale.converted_at ? formatDate(sale.converted_at) : 'Not yet'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Converted by</dt>
                                <dd className="text-slate-900">
                                    {sale.converter?.name ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Project status</dt>
                                <dd>
                                    {sale.project ? (
                                        <StatusBadge status={sale.project.status} />
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </DataPanel>

                    <DataPanel
                        title="Collections"
                        description="Partial or full transfers into selected company accounts"
                        className="lg:col-span-2"
                        noPadding
                    >
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
                                    {payments.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-8 text-center text-slate-500"
                                            >
                                                No collections yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        payments.map((payment) => (
                                            <tr key={payment.id}>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {payment.occurred_at
                                                        ? formatDate(payment.occurred_at)
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-slate-900">
                                                    {payment.account?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium text-slate-900">
                                                    {formatCurrency(payment.amount)}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {payment.method ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-slate-600">
                                                    {payment.reference_no ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {payment.recorder?.name ?? '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </DataPanel>
                </div>
            </div>

            <Dialog
                open={collectOpen}
                onOpenChange={(next) => (next ? openCollectDialog() : closeCollectDialog())}
                title={isLoss ? 'Record loss against company account' : 'Collect receivable'}
                description={
                    isLoss
                        ? `Outstanding loss: ${formatCurrency(sale.outstanding_amount)} (negative amount debits the company account)`
                        : `Outstanding: ${formatCurrency(sale.outstanding_amount)}`
                }
            >
                <form onSubmit={submitCollection} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="money_account_id">Company account</Label>
                        <select
                            id="money_account_id"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                            value={data.money_account_id}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                            required
                        >
                            <option value="">Select account…</option>
                            {manager_accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name} ({formatCurrency(account.balance)})
                                </option>
                            ))}
                        </select>
                        {formErrors.money_account_id && (
                            <p className="text-sm text-red-600">{formErrors.money_account_id}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="amount">Amount</Label>
                        {isLoss ? (
                            <Input
                                id="amount"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                required
                            />
                        ) : (
                            <AmountInput
                                id="amount"
                                value={data.amount}
                                onValueChange={(value) => setData('amount', value)}
                                required
                            />
                        )}
                        {formErrors.amount && (
                            <p className="text-sm text-red-600">{formErrors.amount}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="method">Payment method</Label>
                        <PaymentMethodSelect
                            id="method"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                            optional
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reference_no">Reference</Label>
                        <Input
                            id="reference_no"
                            value={data.reference_no}
                            onChange={(e) => setData('reference_no', e.target.value)}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="notes">Notes</Label>
                        <Input
                            id="notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                    </div>

                    <DialogFormActions
                        onCancel={closeCollectDialog}
                        processing={processing}
                        submitLabel="Record collection"
                        processingLabel="Recording…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
