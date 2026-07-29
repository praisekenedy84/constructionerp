import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

interface InventoryNavProps {
    active: 'items' | 'balances' | 'issues' | 'transactions';
}

const links = [
    {
        key: 'items' as const,
        step: '1',
        label: 'Items',
        hint: 'Define catalog',
        href: '/inventory/items',
    },
    {
        key: 'balances' as const,
        step: '2',
        label: 'On hand',
        hint: 'Receive & move',
        href: '/inventory/balances',
    },
    {
        key: 'issues' as const,
        step: '3',
        label: 'Hand over',
        hint: 'Issue to site',
        href: '/inventory/issues',
    },
    {
        key: 'transactions' as const,
        step: '4',
        label: 'History',
        hint: 'Full ledger',
        href: '/inventory/transactions',
    },
];

export default function InventoryNav({ active }: InventoryNavProps) {
    return (
        <div className="space-y-2">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                Stock flow
            </p>
            <nav className="flex flex-wrap gap-2" aria-label="Inventory steps">
                {links.map((link, index) => (
                    <Link
                        key={link.key}
                        href={link.href}
                        className={cn(
                            'flex min-w-[9.5rem] flex-1 items-start gap-2 rounded-md border px-3 py-2 transition-colors sm:flex-none',
                            active === link.key
                                ? 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200'
                                : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800',
                        )}
                    >
                        <span
                            className={cn(
                                'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                                active === link.key
                                    ? 'bg-blue-700 text-white dark:bg-blue-400 dark:text-slate-900'
                                    : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
                            )}
                        >
                            {link.step}
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">{link.label}</span>
                            <span className="block text-xs text-slate-500 dark:text-slate-400">
                                {link.hint}
                            </span>
                        </span>
                        {index < links.length - 1 && (
                            <span className="sr-only">then</span>
                        )}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
