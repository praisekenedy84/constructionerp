import PlatformUserCreate from '@/Components/Admin/PlatformUserCreate';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { Button } from '@/Components/ui/button';
import { coerceListingFilters } from '@/lib/listing';
import { ListingFilters, Paginated } from '@/types';
import { router } from '@inertiajs/react';
import { UserCheck, UserX } from 'lucide-react';


interface TenantUser {
    username: string;
    phone: string | null;
    id: number;
    name: string;
    email: string;
    roles: string[] | string | { name?: string }[] | null;
    is_locked: boolean;
    locked_at: string | null;
    locked_reason: string | null;
}

export interface TenantUsersPanelProps {
    assignable_roles: string[];
    companies: { id: string; name: string }[];
    company_grants: { tenant_user_id: number; tenant_id: string }[];
    tenant: {
        id: string;
        name: string;
        slug: string;
        status: string;
    };
    users: Paginated<TenantUser>;
    filters?: ListingFilters;
}

function formatRoles(roles: TenantUser['roles']): string {
    if (!roles) {
        return '—';
    }

    if (typeof roles === 'string') {
        return roles;
    }

    if (!Array.isArray(roles)) {
        return '—';
    }

    return roles
        .map((role) => (typeof role === 'string' ? role : role?.name ?? '—'))
        .filter(Boolean)
        .join(', ') || '—';
}

export default function TenantUsersPanel({
    tenant,
    companies,
    company_grants,
    assignable_roles,
    users,
    filters: rawFilters,
}: TenantUsersPanelProps) {
    const filters = coerceListingFilters(rawFilters);
    const rows = users?.data ?? [];

    function lockUser(user: TenantUser) {
        const reason = prompt(`Lock ${user.name}? Optional reason:`) ?? '';
        router.post(`/platform/tenants/${tenant.id}/users/${user.id}/lock`, { reason });
    }

    function unlockUser(user: TenantUser) {
        if (!confirm(`Unlock ${user.name}?`)) return;
        router.post(`/platform/tenants/${tenant.id}/users/${user.id}/unlock`);
    }

    function impersonate(user: TenantUser) {
        if (!confirm(`Impersonate ${user.name}? You will enter their tenant workspace.`)) return;
        router.post(`/platform/tenants/${tenant.id}/users/${user.id}/impersonate`);
    }

    return <div className="space-y-6">
                {tenant.status === 'active' && <PlatformUserCreate tenantId={tenant.id} roles={assignable_roles} />}

                <ListToolbar
                    baseUrl={`/platform/tenants/${tenant.id}`}
                    filters={filters}
                    searchPlaceholder="Search name, email…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'email', label: 'Email' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Users" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-800">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Email</th>
                                <th className="px-6 py-3 font-medium">Role</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No users found for this tenant.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((user) => (
                                    <tr key={user.id} className="text-slate-700 dark:text-slate-300">
                                        <td className="px-6 py-4 font-medium text-slate-900 dark:text-white">
                                            {user.name}<p className="text-xs text-slate-500">{user.username}</p>
                                        </td>
                                        <td className="px-6 py-4">{user.email}</td>
                                        <td className="px-6 py-4">{formatRoles(user.roles)}</td>
                                        <td className="px-6 py-4">
                                            {user.is_locked ? (
                                                <span className="text-amber-600 dark:text-amber-400">Locked</span>
                                            ) : (
                                                <span className="text-green-600 dark:text-green-400">Active</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <details><summary>Read-only companies</summary>{companies.map(company => <label className="block" key={company.id}><input type="checkbox" checked={company_grants.some(grant => grant.tenant_user_id === user.id && grant.tenant_id === company.id)} onChange={event => {
                                                const ids = company_grants.filter(grant => grant.tenant_user_id === user.id).map(grant => grant.tenant_id).filter(id => id !== company.id);
                                                if (event.target.checked) ids.push(company.id);
                                                router.put(`/platform/tenants/${tenant.id}/users/${user.id}/company-access`, { tenant_ids: ids });
                                            }} /> {company.name}</label>)}</details>
                                            <div className="flex justify-end gap-2">
                                                {tenant.status === 'active' && <PlatformUserCreate tenantId={tenant.id} roles={assignable_roles} user={{ ...user, role: formatRoles(user.roles).split(', ')[0] }} />}
                                                {tenant.status === 'active' && !user.is_locked && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => impersonate(user)}
                                                    >
                                                        <UserCheck className="mr-1 h-3 w-3" />
                                                        Impersonate
                                                    </Button>
                                                )}
                                                {user.is_locked ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="border-green-800 text-green-400"
                                                        onClick={() => unlockUser(user)}
                                                    >
                                                        <UserX className="mr-1 h-3 w-3" />
                                                        Unlock
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="border-red-800 text-red-400"
                                                        onClick={() => lockUser(user)}
                                                    >
                                                        <UserX className="mr-1 h-3 w-3" />
                                                        Lock
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    {users && <PaginationLinks paginator={users} />}
                </DataPanel>
    </div>;
}
