import AppShell from '@/Components/Layout/AppShell';
import PlatformShell from '@/Components/Layout/PlatformShell';
import PermissionDenied from '@/Components/Shared/PermissionDenied';
import ThemeToggle from '@/Components/Shared/ThemeToggle';
import { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';

interface ErrorPageProps extends PageProps {
    status: number;
    message?: string | null;
}

const defaultMessages: Record<number, string> = {
    403: 'You do not have permission to perform this action.',
    404: 'The page you are looking for could not be found.',
    500: 'The server encountered an unexpected error.',
    503: 'The application is temporarily unavailable.',
};

export default function ErrorPage() {
    const { status, message, auth, uiSettings } = usePage<ErrorPageProps>().props;
    const resolvedMessage =
        message && message !== 'Forbidden' && message !== 'Not Found'
            ? message
            : (defaultMessages[status] ?? 'An error occurred while processing your request.');

    const content = (
        <PermissionDenied
            status={status}
            message={resolvedMessage}
            backHref={auth.platform_admin ? '/platform' : '/dashboard'}
            backLabel={auth.platform_admin ? 'Platform overview' : 'Go to dashboard'}
        />
    );

    if (auth.user) {
        return (
            <AppShell title={`Error ${status}`}>
                <Head title={`Error ${status}`} />
                <div className="flex min-h-[60vh] items-center justify-center px-4 py-10">
                    {content}
                </div>
            </AppShell>
        );
    }

    if (auth.platform_admin) {
        return (
            <PlatformShell title={`Error ${status}`}>
                <Head title={`Error ${status}`} />
                <div className="flex min-h-[60vh] items-center justify-center px-4 py-10">
                    {content}
                </div>
            </PlatformShell>
        );
    }

    return (
        <>
            <Head title={`Error ${status}`} />
            <div className="relative flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 dark:bg-slate-950">
                <div className="absolute right-4 top-4">
                    <ThemeToggle className="text-slate-500 dark:text-slate-400" />
                </div>
                <div className="mb-8 flex flex-col items-center gap-3">
                    <div className="flex items-center gap-2 text-slate-900 dark:text-white">
                        <Building2 className="h-6 w-6 text-blue-700 dark:text-blue-400" />
                        <span className="text-sm font-semibold">{uiSettings.app_name}</span>
                    </div>
                    {content}
                </div>
            </div>
        </>
    );
}
