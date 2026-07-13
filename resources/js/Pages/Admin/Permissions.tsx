import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import AdminNav from '@/Components/Admin/AdminNav';
import { Button } from '@/Components/ui/button';
import { PageProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

interface RolePermissions {
    name: string;
    permissions: string[];
    expected_permissions: string[];
}

interface AdminPermissionsProps extends PageProps {
    modules: string[];
    actions: string[];
    roles: RolePermissions[];
    editable_roles: string[];
    policy_defaults: Record<string, string[]>;
}

export default function AdminPermissions() {
    const { modules, actions, roles, editable_roles, policy_defaults } =
        usePage<AdminPermissionsProps>().props;

    const [selectedRole, setSelectedRole] = useState(editable_roles[0] ?? roles[0]?.name ?? '');

    const currentRole = roles.find((r) => r.name === selectedRole);
    const policyDefault = policy_defaults[selectedRole] ?? [];

    const form = useForm<{ permissions: string[] }>({
        permissions: currentRole?.permissions ?? [],
    });

    const granted = useMemo(() => new Set(form.data.permissions), [form.data.permissions]);

    function selectRole(role: string) {
        setSelectedRole(role);
        const roleData = roles.find((r) => r.name === role);
        form.setData('permissions', roleData?.permissions ?? []);
    }

    function togglePermission(key: string) {
        const next = granted.has(key)
            ? form.data.permissions.filter((p) => p !== key)
            : [...form.data.permissions, key];
        form.setData('permissions', next.sort());
    }

    function applyPolicyDefault() {
        form.setData('permissions', [...policyDefault].sort());
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.patch(`/admin/permissions/roles/${encodeURIComponent(selectedRole)}`);
    }

    return (
        <AppShell title="Permission Administration">
            <Head title="Permissions" />
            <div className="space-y-6">
                <PageHeader
                    title="Role permissions"
                    description="Configure what each role can do in your company. Users inherit permissions from their assigned role."
                    actions={
                        <Link href="/admin/permissions/sync" method="post" as="button">
                            <Button variant="outline">Reset all to defaults</Button>
                        </Link>
                    }
                />
                <AdminNav active="permissions" />

                <div className="flex flex-wrap gap-2">
                    {editable_roles.map((role) => (
                        <button
                            key={role}
                            type="button"
                            onClick={() => selectRole(role)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                                selectedRole === role
                                    ? 'bg-blue-50 text-blue-700'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            }`}
                        >
                            {role}
                        </button>
                    ))}
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div className="mb-4 flex items-center justify-between">
                        <p className="text-sm text-slate-500">
                            {form.data.permissions.length} capabilities granted
                        </p>
                        <Button type="button" variant="outline" size="sm" onClick={applyPolicyDefault}>
                            Load policy default
                        </Button>
                    </div>
                    <DataPanel title={`Permissions for ${selectedRole}`}>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Module</th>
                                        {actions.map((action) => (
                                            <th key={action} className="px-2 pb-2 text-center font-medium">
                                                {action}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {modules.map((module) => (
                                        <tr key={module}>
                                            <td className="py-2 font-medium capitalize text-slate-900">
                                                {module}
                                            </td>
                                            {actions.map((action) => {
                                                const key = `${module}:${action}`;
                                                const checked = granted.has(key);
                                                const inDefault = policyDefault.includes(key);

                                                return (
                                                    <td key={key} className="px-2 py-2 text-center">
                                                        <label className="inline-flex cursor-pointer items-center justify-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={checked}
                                                                onChange={() => togglePermission(key)}
                                                                className="h-4 w-4"
                                                            />
                                                            {inDefault && !checked && (
                                                                <span
                                                                    className="ml-1 text-amber-500"
                                                                    title="Policy default"
                                                                >
                                                                    ○
                                                                </span>
                                                            )}
                                                        </label>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </DataPanel>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving…' : `Save permissions for ${selectedRole}`}
                    </Button>
                </form>
            </div>
        </AppShell>
    );
}
