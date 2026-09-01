import { cn } from '@/lib/utils';
import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: string;
    actions?: ReactNode;
    className?: string;
}

export default function PageHeader({ title, description, actions, className }: PageHeaderProps) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between',
                className,
            )}
        >
            <div className="min-w-0">
                <h2 className="break-words text-2xl font-bold text-slate-900 dark:text-white">
                    {title}
                </h2>
                {description && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{description}</p>}
            </div>
            {actions && (
                <div className="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto sm:shrink-0">
                    {actions}
                </div>
            )}
        </div>
    );
}
