export function formatCurrency(amount: number | string | null | undefined, currency = 'TZS'): string {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const value = typeof amount === 'string' ? parseFloat(amount) : amount;

    if (Number.isNaN(value)) {
        return '—';
    }

    return new Intl.NumberFormat('en-TZ', {
        style: 'currency',
        currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value);
}

export function formatDate(date: string | Date | null | undefined): string {
    if (!date) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-TZ', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(new Date(date));
}

export function formatPercent(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '0.0%';
    }

    const num = typeof value === 'string' ? parseFloat(value) : value;

    if (Number.isNaN(num)) {
        return '0.0%';
    }

    return `${num.toFixed(1)}%`;
}
