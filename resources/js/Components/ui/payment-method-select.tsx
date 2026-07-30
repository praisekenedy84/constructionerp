import { SelectHTMLAttributes } from 'react';

interface PaymentMethodSelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
    optional?: boolean;
}

export function PaymentMethodSelect({
    optional = false,
    className = '',
    required,
    ...props
}: PaymentMethodSelectProps) {
    return (
        <select
            className={`flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800 ${className}`}
            required={required ?? !optional}
            {...props}
        >
            {optional && <option value="">Select payment method (optional)</option>}
            <option value="cash">Cash</option>
            <option value="mobile">Mobile payment</option>
            <option value="bank">Bank</option>
        </select>
    );
}
