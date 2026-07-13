import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function ProcurementIndex() {
    return (
        <AppShell title="Procurement">
            <Head title="Procurement" />
            <div className="space-y-6">
                <PageHeader
                    title="Procurement"
                    description="Suppliers, purchase orders, and goods receipts."
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Link
                        href="/procurement/suppliers"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Suppliers</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Manage supplier directory and performance ratings.
                        </p>
                    </Link>
                    <Link
                        href="/procurement/purchase-orders"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Purchase Orders</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Create POs from approved requisitions.
                        </p>
                    </Link>
                    <Link
                        href="/procurement/goods-receipts"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Goods Receipts</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Record GRNs and update received quantities.
                        </p>
                    </Link>
                </div>
            </div>
        </AppShell>
    );
}
