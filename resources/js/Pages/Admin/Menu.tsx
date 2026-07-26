import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import AdminNav from '@/Components/Admin/AdminNav';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface MenuCatalogItem {
    key: string;
    label: string;
    href: string;
    permission: string | null;
    group?: string;
    active_path?: string;
    children?: MenuCatalogItem[];
}

interface AdminMenuProps extends PageProps {
    menu_catalog: MenuCatalogItem[];
    roles: string[];
    nav_overrides: {
        hidden: string[];
        role_hidden: Record<string, string[]>;
    };
}

export default function AdminMenu() {
    const { menu_catalog, roles, nav_overrides } = usePage<AdminMenuProps>().props;
    const [selectedRole, setSelectedRole] = useState(roles[0] ?? '');

    const { data, setData, post, processing } = useForm({
        hidden: nav_overrides.hidden ?? [],
        role_hidden: nav_overrides.role_hidden ?? {},
    });

    function isHiddenForRole(href: string, role: string): boolean {
        return (data.role_hidden[role] ?? []).includes(href);
    }

    function toggleRoleItem(href: string, role: string) {
        const current = data.role_hidden[role] ?? [];
        const next = current.includes(href)
            ? current.filter((h) => h !== href)
            : [...current, href];

        setData('role_hidden', { ...data.role_hidden, [role]: next });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/admin/menu');
    }

    const grouped = menu_catalog.reduce<Record<string, MenuCatalogItem[]>>((acc, item) => {
        (acc[item.group ?? 'Other'] ??= []).push(item);
        return acc;
    }, {});

    return (
        <AppShell title="Menu Configuration">
            <Head title="Menu Configuration" />
            <div className="space-y-6">
                <PageHeader
                    title="Menu Items"
                    description="Control which navigation links appear per role. Hiding a link does not revoke access — server permissions still enforce actions."
                />
                <AdminNav active="menu" />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="Per-role visibility">
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <label className="text-sm font-medium text-slate-700">Configure role</label>
                            <select
                                className="h-10 rounded-md border border-slate-200 px-3 text-sm"
                                value={selectedRole}
                                onChange={(e) => setSelectedRole(e.target.value)}
                            >
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-6">
                            {Object.entries(grouped).map(([group, items]) => (
                                <div key={group}>
                                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {group}
                                    </h3>
                                    <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                                        {items.map((item) => (
                                            <MenuVisibilityRow
                                                key={item.key}
                                                item={item}
                                                selectedRole={selectedRole}
                                                isHiddenForRole={isHiddenForRole}
                                                toggleRoleItem={toggleRoleItem}
                                            />
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </DataPanel>

                    <Button type="submit" disabled={processing}>
                        {processing ? 'Saving…' : 'Save menu configuration'}
                    </Button>
                </form>
            </div>
        </AppShell>
    );
}

function MenuVisibilityRow({
    item,
    selectedRole,
    isHiddenForRole,
    toggleRoleItem,
    nested = false,
}: {
    item: MenuCatalogItem;
    selectedRole: string;
    isHiddenForRole: (href: string, role: string) => boolean;
    toggleRoleItem: (href: string, role: string) => void;
    nested?: boolean;
}) {
    // Parent modules are hidden via active_path when present (legacy `/finance`, `/inventory`, …).
    const toggleHref = nested ? item.href : (item.active_path ?? item.href);
    const hidden = isHiddenForRole(toggleHref, selectedRole) || isHiddenForRole(item.href, selectedRole);

    return (
        <>
            <li
                className={cn(
                    'flex items-center justify-between px-4 py-3',
                    nested && 'bg-slate-50/80 pl-10',
                )}
            >
                <div>
                    <p className="text-sm font-medium text-slate-900">
                        {nested ? `↳ ${item.label}` : item.label}
                    </p>
                    <p className="text-xs text-slate-500">
                        {item.active_path && !nested ? `${item.active_path} → ${item.href}` : item.href}
                        {item.permission && (
                            <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 font-mono">
                                requires {item.permission}
                            </span>
                        )}
                        {!item.permission && nested && (
                            <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 font-mono">
                                inherits parent permission
                            </span>
                        )}
                    </p>
                </div>
                <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        type="checkbox"
                        checked={!hidden}
                        onChange={() => toggleRoleItem(toggleHref, selectedRole)}
                    />
                    Show in menu
                </label>
            </li>
            {item.children?.map((child) => (
                <MenuVisibilityRow
                    key={child.key}
                    item={child}
                    selectedRole={selectedRole}
                    isHiddenForRole={isHiddenForRole}
                    toggleRoleItem={toggleRoleItem}
                    nested
                />
            ))}
        </>
    );
}
