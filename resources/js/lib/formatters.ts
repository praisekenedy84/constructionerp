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

/** Quantities and rates: up to 3 decimals, trailing zeros stripped (10 not 10.000). */
export function formatQuantity(value: number | string | null | undefined, maxDecimals = 3): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const num = typeof value === 'string' ? parseFloat(value) : value;

    if (Number.isNaN(num)) {
        return '—';
    }

    return new Intl.NumberFormat('en-TZ', {
        minimumFractionDigits: 0,
        maximumFractionDigits: maxDecimals,
    }).format(num);
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

/** Strip commas / currency noise → raw numeric string for forms/API. */
export function parseAmountInput(display: string): string {
    return display.replace(/,/g, '').replace(/[^\d.]/g, '');
}

/**
 * Format a raw amount for live typing: 1000000 → 1,000,000
 * Keeps a trailing "." while the user is entering decimals.
 */
export function formatAmountInput(raw: string, maxDecimals = 2): string {
    if (raw === '' || raw === null || raw === undefined) {
        return '';
    }

    let cleaned = String(raw).replace(/[^\d.]/g, '');
    const negative = cleaned.startsWith('-');
    cleaned = cleaned.replace(/-/g, '');

    const firstDot = cleaned.indexOf('.');
    let intPart = firstDot === -1 ? cleaned : cleaned.slice(0, firstDot);
    let decPart = firstDot === -1 ? null : cleaned.slice(firstDot + 1).replace(/\./g, '');

    intPart = intPart.replace(/^0+(?=\d)/, '');
    if (intPart === '') {
        intPart = '0';
    }

    if (decPart !== null) {
        decPart = decPart.slice(0, maxDecimals);
    }

    const withCommas = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const sign = negative ? '-' : '';

    if (decPart === null) {
        return `${sign}${withCommas}`;
    }

    return `${sign}${withCommas}.${decPart}`;
}
