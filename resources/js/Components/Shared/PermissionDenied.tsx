import { LinkButton } from '@/Components/Shared/LinkButton';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { ArrowLeft, Home, ShieldAlert } from 'lucide-react';
import { ReactNode } from 'react';

interface PermissionDeniedProps {
    title?: string;
    message: string;
    status?: number;
    hint?: string;
    backHref?: string;
    backLabel?: string;
    actions?: ReactNode;
    className?: string;
}

const statusCopy: Record<number, { title: string; hint: string }> = {
    403: {
        title: 'Access restricted',
        hint: 'You are not allowed to perform this action with your current account.',
    },
    404: {
        title: 'Page not found',
        hint: 'The page you requested does not exist or may have been moved.',
    },
    500: {
        title: 'Something went wrong',
        hint: 'An unexpected error occurred. Try again, or contact support if the problem continues.',
    },
    503: {
        title: 'Service unavailable',
        hint: 'The system is temporarily unavailable. Please try again shortly.',
    },
};

export default function PermissionDenied({
    title,
    message,
    status = 403,
    hint,
    backHref = '/dashboard',
    backLabel = 'Go to dashboard',
    actions,
    className,
}: PermissionDeniedProps) {
    const defaults = statusCopy[status] ?? statusCopy[403];

    return (
        <div
            className={cn(
                'mx-auto flex w-full max-w-xl flex-col items-center rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900',
                className,
            )}
        >
            <div
                className={cn(
                    'mb-5 flex h-14 w-14 items-center justify-center rounded-full',
                    status === 403
                        ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                        : status === 404
                          ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                          : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300',
                )}
            >
                <ShieldAlert className="h-7 w-7" />
            </div>

            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                Error {status}
            </p>
            <h2 className="mt-2 text-xl font-bold text-slate-900 dark:text-white">
                {title ?? defaults.title}
            </h2>
            <p className="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                {message}
            </p>
            <p className="mt-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                {hint ?? defaults.hint}
            </p>

            <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
                {actions ?? (
                    <>
                        <LinkButton href={backHref}>
                            <Home className="mr-2 h-4 w-4" />
                            {backLabel}
                        </LinkButton>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => window.history.back()}
                        >
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Go back
                        </Button>
                    </>
                )}
            </div>
        </div>
    );
}
