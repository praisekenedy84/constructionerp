import { cn } from '@/lib/utils';

type StatusVariant = 'default' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const variantStyles: Record<StatusVariant, string> = {
    default: 'bg-slate-100 text-slate-700',
    success: 'bg-green-100 text-green-800',
    warning: 'bg-amber-100 text-amber-800',
    danger: 'bg-red-100 text-red-800',
    info: 'bg-blue-100 text-blue-800',
    neutral: 'bg-slate-100 text-slate-600',
};

const statusMap: Record<string, StatusVariant> = {
    planning: 'neutral',
    active: 'success',
    on_hold: 'warning',
    closed: 'neutral',
    loss: 'danger',
    draft: 'neutral',
    submitted: 'info',
    under_review: 'warning',
    approved: 'success',
    amended: 'warning',
    rejected: 'danger',
    partially_fulfilled: 'warning',
    fulfilled: 'success',
    cancelled: 'danger',
    pending: 'warning',
    received: 'success',
    sent: 'info',
    confirmed: 'info',
    partially_received: 'warning',
    fully_received: 'success',
    present: 'success',
    absent: 'danger',
    half_day: 'warning',
    leave: 'info',
    posted: 'success',
    available: 'success',
    assigned: 'info',
    open: 'info',
    receivable: 'warning',
    unpaid: 'danger',
    partially_paid: 'warning',
    paid: 'success',
    under_maintenance: 'warning',
    retired: 'neutral',
    certified: 'success',
    good: 'success',
    damaged: 'danger',
    partial: 'warning',
    direct: 'info',
    indirect: 'neutral',
    suspended: 'danger',
    in_progress: 'info',
    succeeded: 'success',
    unsatisfactory: 'danger',
    held: 'warning',
    released: 'success',
    forfeited: 'danger',
    none: 'neutral',
};

interface StatusBadgeProps {
    status: string;
    className?: string;
}

export default function StatusBadge({ status, className }: StatusBadgeProps) {
    const variant = statusMap[status] ?? 'default';
    const label = status.replace(/_/g, ' ');

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                variantStyles[variant],
                className,
            )}
        >
            {label}
        </span>
    );
}
