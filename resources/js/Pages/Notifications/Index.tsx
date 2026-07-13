import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { Button } from '@/Components/ui/button';
import PageHeader from '@/Components/Shared/PageHeader';
import { formatDate } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';

interface NotificationRow {
    id: number;
    type: string;
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string;
}

interface NotificationsIndexProps extends PageProps {
    notifications: Paginated<NotificationRow>;
    filters: ListingFilters;
}

export default function NotificationsIndex() {
    const { notifications, filters } = usePage<NotificationsIndexProps>().props;
    const rows = notifications.data ?? [];

    return (
        <AppShell title="Notifications">
            <Head title="Notifications" />
            <div className="space-y-6">
                <PageHeader title="Notifications" description="System alerts and workflow updates." />

                <ListToolbar
                    baseUrl="/notifications"
                    filters={filters}
                    searchPlaceholder="Search notification type…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date' },
                        { value: 'type', label: 'Type' },
                        { value: 'read_at', label: 'Read at' },
                    ]}
                />

                <DataPanel title="Inbox" noPadding>
                    {rows.length === 0 ? (
                        <p className="px-6 py-12 text-center text-sm text-slate-500">
                            No notifications yet.
                        </p>
                    ) : (
                        <>
                            <ul className="divide-y divide-slate-100">
                                {rows.map((notification) => (
                                    <li
                                        key={notification.id}
                                        className={`flex items-center justify-between px-6 py-4 ${
                                            notification.read_at ? 'bg-white' : 'bg-blue-50/50'
                                        }`}
                                    >
                                        <div>
                                            <p className="text-sm font-medium capitalize text-slate-900">
                                                {notification.type.replace(/_/g, ' ')}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {formatDate(notification.created_at)}
                                            </p>
                                        </div>
                                        {!notification.read_at && (
                                            <Button variant="outline" size="sm" asChild>
                                                <Link
                                                    href={`/notifications/${notification.id}/read`}
                                                    method="post"
                                                    as="button"
                                                >
                                                    <Check className="h-4 w-4" />
                                                    Mark read
                                                </Link>
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <PaginationLinks paginator={notifications} />
                        </>
                    )}
                </DataPanel>
            </div>
        </AppShell>
    );
}
