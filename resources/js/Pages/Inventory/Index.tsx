import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function InventoryIndex() {
    return (
        <AppShell title="Inventory">
            <Head title="Inventory" />
            <div className="space-y-6">
                <PageHeader
                    title="Inventory"
                    description="Stock balances, issues, and transaction history."
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Link
                        href="/inventory/balances"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Stock Balances</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Quantities on hand by location and valuation.
                        </p>
                    </Link>
                    <Link
                        href="/inventory/issues"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Issues</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Stock issued against requisitions and work sections.
                        </p>
                    </Link>
                    <Link
                        href="/inventory/transactions"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Transactions</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Full ledger of receipts, issues, transfers, and adjustments.
                        </p>
                    </Link>
                </div>
            </div>
        </AppShell>
    );
}
