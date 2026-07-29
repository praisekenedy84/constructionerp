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
    catalog: Record<string, string[]>;
    module_labels: Record<string, string>;
    action_labels: Record<string, string>;
    action_descriptions: Record<string, string>;
    roles: RolePermissions[];
    editable_roles: string[];
    policy_defaults: Record<string, string[]>;
}

export default function AdminPermissions() {
    const {
        modules,
        actions,
        catalog,
        module_labels,
        action_labels,
        action_descriptions,
        roles,
        editable_roles,
        policy_defaults,
    } = usePage<AdminPermissionsProps>().props;

    const [selectedRole, setSelectedRole] = useState(editable_roles[0] ?? roles[0]?.name ?? '');

    const currentRole = roles.find((r) => r.name === selectedRole);
    const policyDefault = policy_defaults[selectedRole] ?? [];

    const form = useForm<{ permissions: string[] }>({
        permissions: currentRole?.permissions ?? [],
    });

    const granted = useMemo(() => new Set(form.data.permissions), [form.data.permissions]);

    const visibleActions = useMemo(() => {
        return actions.filter((action) =>
            modules.some((module) => (catalog[module] ?? []).includes(action)),
        );
    }, [actions, catalog, modules]);

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

    function toggleModuleRow(module: string, enable: boolean) {
        const moduleActions = catalog[module] ?? [];
        const keys = moduleActions.map((action) => `${module}:${action}`);
        const without = form.data.permissions.filter((p) => !keys.includes(p));
        form.setData('permissions', (enable ? [...without, ...keys] : without).sort());
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
                    description="Each checked box grants that action immediately. Roles only group people — access is controlled entirely by these checkboxes. Save after changing boxes for a role."
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
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-slate-500">
                            {form.data.permissions.length} capabilities granted for {selectedRole}
                        </p>
                        <Button type="button" variant="outline" size="sm" onClick={applyPolicyDefault}>
                            Load policy default
                        </Button>
                    </div>

                    <DataPanel title={`Capabilities for ${selectedRole}`}>
                        <div className="mb-4 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                            Tip: check a box → that role can perform the action. Uncheck → they cannot. No separate role gate is required.
                        </div>

                        <div className="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {visibleActions.map((action) => (
                                <div key={action} className="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
                                    <p className="text-xs font-semibold text-slate-800">
                                        {action_labels[action] ?? action}
                                    </p>
                                    <p className="text-[11px] text-slate-500">
                                        {action_descriptions[action] ?? ''}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Module</th>
                                        <th className="px-2 pb-2 text-center font-medium">All</th>
                                        {visibleActions.map((action) => (
                                            <th
                                                key={action}
                                                className="px-2 pb-2 text-center font-medium"
                                                title={action_descriptions[action] ?? action}
                                            >
                                                {action_labels[action] ?? action}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {modules.map((module) => {
                                        const moduleActions = catalog[module] ?? [];
                                        const moduleKeys = moduleActions.map(
                                            (action) => `${module}:${action}`,
                                        );
                                        const allOn = moduleKeys.every((key) => granted.has(key));

                                        return (
                                            <tr key={module}>
                                                <td className="py-2 font-medium text-slate-900">
                                                    {module_labels[module] ?? module}
                                                </td>
                                                <td className="px-2 py-2 text-center">
                                                    <input
                                                        type="checkbox"
                                                        checked={allOn && moduleKeys.length > 0}
                                                        onChange={(e) =>
                                                            toggleModuleRow(module, e.target.checked)
                                                        }
                                                        className="h-4 w-4"
                                                        title={`Toggle all ${module_labels[module] ?? module} capabilities`}
                                                    />
                                                </td>
                                                {visibleActions.map((action) => {
                                                    const allowed = moduleActions.includes(action);
                                                    const key = `${module}:${action}`;
                                                    const checked = granted.has(key);
                                                    const inDefault = policyDefault.includes(key);

                                                    if (!allowed) {
                                                        return (
                                                            <td
                                                                key={key}
                                                                className="px-2 py-2 text-center text-slate-300"
                                                            >
                                                                —
                                                            </td>
                                                        );
                                                    }

                                                    return (
                                                        <td key={key} className="px-2 py-2 text-center">
                                                            <label className="inline-flex cursor-pointer items-center justify-center">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={checked}
                                                                    onChange={() =>
                                                                        togglePermission(key)
                                                                    }
                                                                    className="h-4 w-4"
                                                                    title={`${module_labels[module] ?? module}: ${action_labels[action] ?? action}`}
                                                                />
                                                                {inDefault && !checked && (
                                                                    <span
                                                                        className="ml-1 text-amber-500"
                                                                        title="Included in policy default"
                                                                    >
                                                                        ○
                                                                    </span>
                                                                )}
                                                            </label>
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        );
                                    })}
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
