import { BudgetTransaction, CashAllocation } from '@/types';
import { formatCurrency } from '@/lib/formatters';

export function formatChartCurrency(value: number): string {
    if (Math.abs(value) >= 1_000_000) {
        return `${(value / 1_000_000).toFixed(1)}M`;
    }

    if (Math.abs(value) >= 1_000) {
        return `${(value / 1_000).toFixed(0)}K`;
    }

    return value.toFixed(0);
}

export function formatStatusLabel(status: string): string {
    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export function aggregateCashFlowTimeline(allocations: CashAllocation[]) {
    const sorted = [...allocations].sort(
        (a, b) => new Date(a.requested_at).getTime() - new Date(b.requested_at).getTime(),
    );

    let cumulativeReceived = 0;
    let cumulativeUtilized = 0;

    return sorted.map((alloc) => {
        cumulativeReceived += parseFloat(alloc.received_amount) || 0;
        cumulativeUtilized += parseFloat(alloc.utilized_amount) || 0;

        return {
            date: new Date(alloc.requested_at).toLocaleDateString('en-TZ', {
                month: 'short',
                day: 'numeric',
            }),
            received: cumulativeReceived,
            utilized: cumulativeUtilized,
        };
    });
}

export function aggregateBudgetByType(transactions: BudgetTransaction[]) {
    const totals = new Map<string, number>();

    for (const tx of transactions) {
        const amount = Math.abs(parseFloat(tx.amount) || 0);
        if (amount === 0) continue;

        const label = formatStatusLabel(tx.type.toLowerCase());
        totals.set(label, (totals.get(label) ?? 0) + amount);
    }

    return Array.from(totals.entries())
        .map(([name, value]) => ({ name, value }))
        .sort((a, b) => b.value - a.value);
}

export function aggregateBudgetTimeline(transactions: BudgetTransaction[]) {
    const sorted = [...transactions].sort(
        (a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
    );

    let cumulative = 0;

    return sorted.map((tx) => {
        cumulative += Math.abs(parseFloat(tx.amount) || 0);

        return {
            date: new Date(tx.created_at).toLocaleDateString('en-TZ', {
                month: 'short',
                day: 'numeric',
            }),
            spent: cumulative,
        };
    });
}

export function currencyTooltipFormatter(value: number | string | undefined) {
    return formatCurrency(typeof value === 'number' ? value : parseFloat(String(value ?? 0)));
}
