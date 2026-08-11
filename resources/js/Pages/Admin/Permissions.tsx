import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions, FormErrorSummary } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react';

interface RolePermissions {
    name: string;
    permissions: string[];
    expected_permissions: string[];
    is_locked: boolean;
    is_editable: boolean;
    user_count: number;
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
    locked_roles: string[];
    policy_defaults: Record<string, string[]>;
    template_roles: string[];
    selected_role?: string | null;
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
        policy_defaults,
        template_roles,
        selected_role,
    } = usePage<AdminPermissionsProps>().props;

    const initialRole =
        (selected_role && roles.find((r) => r.name === selected_role)?.name) ||
        roles.find((r) => r.is_editable)?.name ||
        roles[0]?.name ||
        '';

    const [selectedRole, setSelectedRole] = useState(initialRole);
    const [createOpen, setCreateOpen] = useState(false);
    const [renameOpen, setRenameOpen] = useState(false);

    const currentRole = roles.find((r) => r.name === selectedRole);
    const isLocked = Boolean(currentRole?.is_locked);
    const isEditable = Boolean(currentRole?.is_editable);
    const policyDefault = policy_defaults[selectedRole] ?? currentRole?.expected_permissions ?? [];
    const hasPolicyDefault = template_roles.includes(selectedRole);

    const form = useForm<{ permissions: string[] }>({
        permissions: currentRole?.permissions ?? [],
    });

    useEffect(() => {
        if (selected_role && roles.some((r) => r.name === selected_role)) {
            setSelectedRole(selected_role);
        }
    }, [selected_role, roles]);

    useEffect(() => {
        const role = roles.find((r) => r.name === selectedRole);
        form.setData('permissions', role?.permissions ?? []);
        form.clearErrors();
        // Sync checkbox state when switching roles or after server refresh.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedRole, roles]);

    const granted = useMemo(() => new Set(form.data.permissions), [form.data.permissions]);

    const visibleActions = useMemo(() => {
        return actions.filter((action) =>
            modules.some((module) => (catalog[module] ?? []).includes(action)),
        );
    }, [actions, catalog, modules]);

    function selectRole(role: string) {
        setSelectedRole(role);
    }

    function togglePermission(key: string) {
        if (!isEditable) {
            return;
        }
        const next = granted.has(key)
            ? form.data.permissions.filter((p) => p !== key)
            : [...form.data.permissions, key];
        form.setData('permissions', next.sort());
    }

    function toggleModuleRow(module: string, enable: boolean) {
        if (!isEditable) {
            return;
        }
        const moduleActions = catalog[module] ?? [];
        const keys = moduleActions.map((action) => `${module}:${action}`);
        const without = form.data.permissions.filter((p) => !keys.includes(p));
        form.setData('permissions', (enable ? [...without, ...keys] : without).sort());
    }

    function applyPolicyDefault() {
        if (!hasPolicyDefault || !isEditable) {
            return;
        }
        form.setData('permissions', [...policyDefault].sort());
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!isEditable) {
            return;
        }
        form.patch(`/admin/permissions/roles/${encodeURIComponent(selectedRole)}`);
    }

    function deleteRole() {
        if (!currentRole || currentRole.is_locked || currentRole.user_count > 0) {
            return;
        }
        if (!window.confirm(`Delete role “${currentRole.name}”? This cannot be undone.`)) {
            return;
        }
        router.delete(`/admin/roles/${encodeURIComponent(currentRole.name)}`);
    }

    return (
        <AppShell title="Permission Administration">
            <Head title="Permissions" />
            <div className="space-y-6">
                <PageHeader
                    title="Roles & permissions"
                    description="Create and rename roles, then grant module capabilities with the checkboxes. System Administrator is locked and always has full access."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={() => setCreateOpen(true)}>
                                <Plus className="mr-1 h-4 w-4" />
                                New role
                            </Button>
                            <Link href="/admin/permissions/sync" method="post" as="button">
                                <Button variant="outline">Reset template roles</Button>
                            </Link>
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
                    <aside className="space-y-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Roles
                        </p>
                        <ul className="space-y-1" role="list">
                            {roles.map((role) => (
                                <li key={role.name}>
                                    <button
                                        type="button"
                                        onClick={() => selectRole(role.name)}
                                        className={cn(
                                            'flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition-colors',
                                            selectedRole === role.name
                                                ? 'bg-blue-50 font-medium text-blue-700'
                                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1 truncate">{role.name}</span>
                                        {role.is_locked && (
                                            <Lock className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-label="Locked" />
                                        )}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </aside>

                    <div className="min-w-0 space-y-4">
                        {currentRole && (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900">
                                        {currentRole.name}
                                    </h2>
                                    <p className="text-sm text-slate-500">
                                        {currentRole.user_count} user
                                        {currentRole.user_count === 1 ? '' : 's'} assigned
                                        {isLocked ? ' · Locked system role' : ''}
                                    </p>
                                </div>
                                {isEditable && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setRenameOpen(true)}
                                        >
                                            <Pencil className="mr-1 h-3.5 w-3.5" />
                                            Rename
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={currentRole.user_count > 0}
                                            title={
                                                currentRole.user_count > 0
                                                    ? 'Reassign users before deleting this role'
                                                    : 'Delete role'
                                            }
                                            onClick={deleteRole}
                                        >
                                            <Trash2 className="mr-1 h-3.5 w-3.5" />
                                            Delete
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}

                        <form onSubmit={submit} className="space-y-4">
                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm text-slate-500">
                                    {form.data.permissions.length} capabilities granted for {selectedRole}
                                </p>
                                {hasPolicyDefault && isEditable && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={applyPolicyDefault}
                                    >
                                        Load policy default
                                    </Button>
                                )}
                            </div>

                            {isLocked && (
                                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    This role is locked and always has every capability. Its permission
                                    matrix cannot be edited, renamed, or deleted.
                                </div>
                            )}

                            <DataPanel title={`Capabilities for ${selectedRole || 'role'}`}>
                                <div className="mb-4 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                                    Tip: check a box → that role can perform the action. Uncheck → they
                                    cannot.
                                </div>

                                <div className="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {visibleActions.map((action) => (
                                        <div
                                            key={action}
                                            className="rounded-md border border-slate-100 bg-slate-50 px-3 py-2"
                                        >
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
                                                const allOn =
                                                    moduleKeys.length > 0 &&
                                                    moduleKeys.every((key) => granted.has(key));

                                                return (
                                                    <tr key={module}>
                                                        <td className="py-2 font-medium text-slate-900">
                                                            {module_labels[module] ?? module}
                                                        </td>
                                                        <td className="px-2 py-2 text-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={allOn}
                                                                disabled={!isEditable}
                                                                onChange={(e) =>
                                                                    toggleModuleRow(
                                                                        module,
                                                                        e.target.checked,
                                                                    )
                                                                }
                                                                className="h-4 w-4"
                                                                title={`Toggle all ${module_labels[module] ?? module} capabilities`}
                                                            />
                                                        </td>
                                                        {visibleActions.map((action) => {
                                                            const allowed =
                                                                moduleActions.includes(action);
                                                            const key = `${module}:${action}`;
                                                            const checked = granted.has(key);
                                                            const inDefault =
                                                                policyDefault.includes(key);

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
                                                                <td
                                                                    key={key}
                                                                    className="px-2 py-2 text-center"
                                                                >
                                                                    <label className="inline-flex cursor-pointer items-center justify-center">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={checked}
                                                                            disabled={!isEditable}
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

                            {isEditable && (
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing
                                        ? 'Saving…'
                                        : `Save permissions for ${selectedRole}`}
                                </Button>
                            )}
                        </form>
                    </div>
                </div>
            </div>

            <CreateRoleDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                templateRoles={template_roles.filter((name) => name !== 'Platform Admin')}
                roles={roles}
            />

            {currentRole && isEditable && (
                <RenameRoleDialog
                    open={renameOpen}
                    onOpenChange={setRenameOpen}
                    roleName={currentRole.name}
                />
            )}
        </AppShell>
    );
}

function CreateRoleDialog({
    open,
    onOpenChange,
    templateRoles,
    roles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    templateRoles: string[];
    roles: RolePermissions[];
}) {
    const form = useForm({
        name: '',
        copy_from: '',
        permissions: [] as string[],
    });

    useEffect(() => {
        if (!open) {
            return;
        }
        form.setData({ name: '', copy_from: '', permissions: [] });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function close() {
        if (!confirmDiscardIfDirty(form.isDirty)) {
            return;
        }
        onOpenChange(false);
        form.reset();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/admin/roles', {
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    }

    const copyOptions = [
        ...templateRoles,
        ...roles.map((r) => r.name).filter((name) => !templateRoles.includes(name)),
    ].filter((name, index, all) => all.indexOf(name) === index && name !== 'Platform Admin');

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                    return;
                }
                onOpenChange(true);
            }}
            title="Create role"
            description="Add a new role, optionally copying permissions from an existing role or policy template."
        >
            <form onSubmit={submit} className="space-y-4">
                <FormErrorSummary errors={form.errors} handled={['name', 'copy_from', 'permissions']} />
                <div className="space-y-2">
                    <Label htmlFor="role-name">Role name</Label>
                    <Input
                        id="role-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                        maxLength={125}
                    />
                    {form.errors.name && (
                        <p className="text-sm text-red-600">{form.errors.name}</p>
                    )}
                </div>
                <div className="space-y-2">
                    <Label htmlFor="copy-from">Start from (optional)</Label>
                    <select
                        id="copy-from"
                        className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                        value={form.data.copy_from}
                        onChange={(e) => form.setData('copy_from', e.target.value)}
                    >
                        <option value="">Empty permissions</option>
                        {copyOptions.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                </div>
                <DialogFormActions
                    onCancel={close}
                    processing={form.processing}
                    submitLabel="Create role"
                    processingLabel="Creating…"
                />
            </form>
        </Dialog>
    );
}

function RenameRoleDialog({
    open,
    onOpenChange,
    roleName,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roleName: string;
}) {
    const form = useForm({ name: roleName });

    useEffect(() => {
        if (!open) {
            return;
        }
        form.setData({ name: roleName });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, roleName]);

    function close() {
        if (!confirmDiscardIfDirty(form.isDirty)) {
            return;
        }
        onOpenChange(false);
        form.reset();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.patch(`/admin/roles/${encodeURIComponent(roleName)}`, {
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                    return;
                }
                onOpenChange(true);
            }}
            title="Rename role"
            description="Users keep this role after rename. Menu visibility and workflow thresholds for this role are updated automatically."
        >
            <form onSubmit={submit} className="space-y-4">
                <FormErrorSummary errors={form.errors} handled={['name']} />
                <div className="space-y-2">
                    <Label htmlFor="rename-role">New name</Label>
                    <Input
                        id="rename-role"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                        maxLength={125}
                    />
                    {form.errors.name && (
                        <p className="text-sm text-red-600">{form.errors.name}</p>
                    )}
                </div>
                <DialogFormActions
                    onCancel={close}
                    processing={form.processing}
                    submitLabel="Rename"
                    processingLabel="Saving…"
                />
            </form>
        </Dialog>
    );
}
