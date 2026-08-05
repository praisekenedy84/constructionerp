import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PaymentMethodSelect } from '@/Components/ui/payment-method-select';
import { formatCurrency } from '@/lib/formatters';
import { MoneyAccount, PageProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface AccountsProps extends PageProps {
    accounts: MoneyAccount[];
    can_manage: boolean;
}

export default function Accounts() {
    const { accounts, can_manage } = usePage<AccountsProps>().props;
    const [createOpen, setCreateOpen] = useState(false);
    const [depositAccount, setDepositAccount] = useState<MoneyAccount | null>(null);

    const createForm = useForm({ name: '', notes: '' });
    const depositForm = useForm({
        amount: '',
        description: '',
        reference_no: '',
        method: '',
    });

    function closeCreate() {
        if (!confirmDiscardIfDirty(createForm.isDirty)) {
            return;
        }
        setCreateOpen(false);
        createForm.reset();
        createForm.clearErrors();
    }

    function submitCreate(e: FormEvent) {
        e.preventDefault();
        createForm.post('/finance/accounts', {
            onSuccess: () => {
                createForm.reset();
                setCreateOpen(false);
            },
        });
    }

    function closeDeposit() {
        if (!confirmDiscardIfDirty(depositForm.isDirty)) {
            return;
        }
        setDepositAccount(null);
        depositForm.reset();
        depositForm.clearErrors();
    }

    function submitDeposit(e: FormEvent) {
        e.preventDefault();
        if (!depositAccount) {
            return;
        }
        depositForm.post(`/finance/accounts/${depositAccount.id}/deposit`, {
            onSuccess: () => {
                depositForm.reset();
                setDepositAccount(null);
            },
        });
    }

    const finance = accounts.find((a) => a.type === 'finance');
    const managerAccounts = accounts.filter((a) => a.type === 'manager');

    return (
        <AppShell title="Accounts">
            <Head title="Accounts" />
            <div className="space-y-6">
                <PageHeader
                    title="Accounts"
                    description="Manager accounts hold source funds (bank, cash, etc.). The Finance Wallet receives approved transfers and pays project or company expenses."
                    actions={
                        can_manage ? (
                            <Button onClick={() => setCreateOpen(true)}>
                                <Plus className="mr-2 h-4 w-4" />
                                New manager account
                            </Button>
                        ) : undefined
                    }
                />

                {finance && (
                    <DataPanel title="Finance Wallet">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-slate-100 p-3">
                                    <Wallet className="h-5 w-5 text-slate-700" />
                                </div>
                                <div>
                                    <p className="font-medium text-slate-900">{finance.name}</p>
                                    <p className="text-sm text-slate-500">
                                        Shared operating balance for all spending
                                    </p>
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl font-bold text-slate-900">
                                    {formatCurrency(finance.balance)}
                                </p>
                                <Link
                                    href="/finance/finance-transactions"
                                    className="text-sm text-blue-700 hover:underline"
                                >
                                    View transactions
                                </Link>
                            </div>
                        </div>
                    </DataPanel>
                )}

                <DataPanel title={`Manager accounts (${managerAccounts.length})`} noPadding>
                    {managerAccounts.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No manager accounts yet. Create one (e.g. “Main Bank”) to record deposits.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                        <th className="px-4 py-3 font-medium">Name</th>
                                        <th className="px-4 py-3 text-right font-medium">Balance</th>
                                        <th className="px-4 py-3 font-medium">Notes</th>
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {managerAccounts.map((account) => (
                                        <tr key={account.id}>
                                            <td className="px-4 py-4 font-medium text-slate-900">
                                                {account.name}
                                            </td>
                                            <td className="px-4 py-4 text-right font-medium">
                                                {formatCurrency(account.balance)}
                                            </td>
                                            <td className="px-4 py-4 text-slate-500">
                                                {account.notes || '—'}
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can_manage && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                depositForm.reset();
                                                                depositForm.clearErrors();
                                                                setDepositAccount(account);
                                                            }}
                                                        >
                                                            Deposit
                                                        </Button>
                                                    )}
                                                    <Link
                                                        href={`/finance/manager-transactions?account_id=${account.id}`}
                                                    >
                                                        <Button size="sm" variant="outline">
                                                            Transactions
                                                        </Button>
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DataPanel>
            </div>

            {can_manage && (
                <Dialog
                    open={createOpen}
                    onOpenChange={(open) => {
                        if (open) {
                            setCreateOpen(true);
                        } else {
                            closeCreate();
                        }
                    }}
                    title="New manager account"
                    description="Create a custom source account such as a bank account or petty cash."
                >
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="account-name">Account name</Label>
                            <Input
                                id="account-name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                placeholder="e.g. CRDB Main"
                                required
                            />
                            {createForm.errors.name && (
                                <p className="text-sm text-red-600">{createForm.errors.name}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="account-notes">Notes</Label>
                            <Input
                                id="account-notes"
                                value={createForm.data.notes}
                                onChange={(e) => createForm.setData('notes', e.target.value)}
                            />
                        </div>
                        <DialogFormActions
                            onCancel={closeCreate}
                            processing={createForm.processing}
                            submitLabel="Create account"
                            processingLabel="Creating…"
                        />
                    </form>
                </Dialog>
            )}

            {depositAccount && (
                <Dialog
                    open={!!depositAccount}
                    onOpenChange={(open) => {
                        if (!open) {
                            closeDeposit();
                        }
                    }}
                    title={`Deposit — ${depositAccount.name}`}
                    description="Record money into this manager account. No attachment required."
                >
                    <form onSubmit={submitDeposit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Amount (TZS)</Label>
                            <AmountInput
                                value={depositForm.data.amount}
                                onValueChange={(v) => depositForm.setData('amount', v)}
                                required
                            />
                            {depositForm.errors.amount && (
                                <p className="text-sm text-red-600">{depositForm.errors.amount}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Description</Label>
                            <Input
                                value={depositForm.data.description}
                                onChange={(e) => depositForm.setData('description', e.target.value)}
                                placeholder="e.g. Bank top-up"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Method</Label>
                            <PaymentMethodSelect
                                value={depositForm.data.method}
                                onChange={(e) => depositForm.setData('method', e.target.value)}
                                optional
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Reference</Label>
                            <Input
                                value={depositForm.data.reference_no}
                                onChange={(e) => depositForm.setData('reference_no', e.target.value)}
                            />
                        </div>
                        <DialogFormActions
                            onCancel={closeDeposit}
                            processing={depositForm.processing}
                            submitLabel="Record deposit"
                            processingLabel="Saving…"
                        />
                    </form>
                </Dialog>
            )}
        </AppShell>
    );
}
