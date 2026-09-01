import { cn } from '@/lib/utils';
import { ReactNode } from 'react';

interface DataPanelProps {
    title?: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    noPadding?: boolean;
}

export default function DataPanel({
    title,
    description,
    actions,
    children,
    className,
    noPadding = false,
}: DataPanelProps) {
    return (
        <section
            className={cn(
                'min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900',
                className,
            )}
        >
            {(title || actions) && (
                <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-slate-800">
                    <div className="min-w-0">
                        {title && (
                            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
                        )}
                        {description && (
                            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{description}</p>
                        )}
                    </div>
                    {actions && (
                        <div className="flex min-w-0 flex-wrap items-center gap-2 sm:shrink-0">
                            {actions}
                        </div>
                    )}
                </div>
            )}
            <div className={cn('min-w-0', !noPadding && 'p-4 sm:p-6')}>{children}</div>
        </section>
    );
}
