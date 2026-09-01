import { cn } from '@/lib/utils';
import * as React from 'react';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
    ({ className, type, ...props }, ref) => {
        return (
            <input
                type={type}
                className={cn(
                    'flex h-10 min-w-0 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 ring-offset-white file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-white dark:ring-offset-slate-950 dark:placeholder:text-slate-500',
                    className,
                )}
                ref={ref}
                {...props}
            />
        );
    },
);
Input.displayName = 'Input';

export { Input };
