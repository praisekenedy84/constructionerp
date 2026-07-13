import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ExportButton from '@/Components/Shared/ExportButton';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatDate } from '@/lib/formatters';
import { AuditLog, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface AuditIndexProps extends PageProps {
    logs: Paginated<AuditLog>;
    filters: ListingFilters & {
        entity_type?: string;
        entity_id?: string;
        action?: string;
    };
}

export default function AuditIndex() {
    const { logs, filters } = usePage<AuditIndexProps>().props;
    const rows = logs.data ?? [];

    return (
        <AppShell title="Audit Trail">
            <Head title="Audit Trail" />
            <div className="space-y-6">
                <PageHeader
                    title="Audit Trail"
                    description="Immutable log of all system mutations."
                    actions={<ExportButton slug="audit-trail" filters={filters} />}
                />

                <ListToolbar
                    baseUrl="/audit"
                    filters={filters}
                    searchPlaceholder="Search entity, action, user…"
                    sortOptions={[
                        { value: 'created_at', label: 'Timestamp' },
                        { value: 'entity_type', label: 'Entity type' },
                        { value: 'action', label: 'Action' },
                        { value: 'entity_id', label: 'Entity ID' },
                    ]}
                    textFilters={[
                        { key: 'entity_type', label: 'Entity type', placeholder: 'e.g. Requisition' },
                        { key: 'action', label: 'Action', placeholder: 'e.g. created' },
                    ]}
                />

                <DataPanel title="Audit Log" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Timestamp</th>
                                <th className="px-6 py-3 font-medium">Entity</th>
                                <th className="px-6 py-3 font-medium">Action</th>
                                <th className="px-6 py-3 font-medium">Performed By</th>
                                <th className="px-6 py-3 font-medium">IP</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No audit entries found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((log) => (
                                    <tr key={log.id} className="hover:bg-slate-50">
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatDate(log.created_at)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="font-medium">{log.entity_type}</span>
                                            <span className="text-slate-400"> #{log.entity_id}</span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={log.action} />
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {log.performer?.name ?? 'System'}
                                        </td>
                                        <td className="px-6 py-4 font-mono text-xs text-slate-500">
                                            {log.ip_address ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={logs} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
