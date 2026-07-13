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
        <div className={cn('rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900', className)}>
            {(title || actions) && (
                <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-slate-800">
                    <div>
                        {title && (
                            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
                        )}
                        {description && (
                            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{description}</p>
                        )}
                    </div>
                    {actions && <div className="flex items-center gap-2">{actions}</div>}
                </div>
            )}
            <div className={cn(!noPadding && 'p-6')}>{children}</div>
        </div>
    );
}
