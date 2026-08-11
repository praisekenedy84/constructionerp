import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, GripVertical } from 'lucide-react';
import { DragEvent, FormEvent, useMemo, useState } from 'react';

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
        order: string[];
        child_order: Record<string, string[]>;
    };
}

function applyKeyOrder<T extends { key: string }>(items: T[], orderedKeys: string[]): T[] {
    if (orderedKeys.length === 0) {
        return items;
    }

    const byKey = new Map(items.map((item) => [item.key, item]));
    const sorted: T[] = [];

    for (const key of orderedKeys) {
        const item = byKey.get(key);
        if (!item) {
            continue;
        }
        sorted.push(item);
        byKey.delete(key);
    }

    for (const item of byKey.values()) {
        sorted.push(item);
    }

    return sorted;
}

function moveKey(keys: string[], fromIndex: number, toIndex: number): string[] {
    if (
        fromIndex < 0 ||
        toIndex < 0 ||
        fromIndex >= keys.length ||
        toIndex >= keys.length ||
        fromIndex === toIndex
    ) {
        return keys;
    }

    const next = [...keys];
    const [moved] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, moved);
    return next;
}

export default function AdminMenu() {
    const { menu_catalog, roles, nav_overrides } = usePage<AdminMenuProps>().props;
    const [selectedRole, setSelectedRole] = useState(roles[0] ?? '');
    const [dragParentKey, setDragParentKey] = useState<string | null>(null);
    const [dragChild, setDragChild] = useState<{ parentKey: string; key: string } | null>(null);

    const catalogByKey = useMemo(() => {
        const map = new Map<string, MenuCatalogItem>();
        for (const item of menu_catalog) {
            map.set(item.key, item);
        }
        return map;
    }, [menu_catalog]);

    const defaultOrder = useMemo(() => menu_catalog.map((item) => item.key), [menu_catalog]);
    const defaultChildOrder = useMemo(() => {
        const map: Record<string, string[]> = {};
        for (const item of menu_catalog) {
            if (item.children?.length) {
                map[item.key] = item.children.map((child) => child.key);
            }
        }
        return map;
    }, [menu_catalog]);

    const { data, setData, post, processing } = useForm({
        hidden: nav_overrides.hidden ?? [],
        role_hidden: nav_overrides.role_hidden ?? {},
        order: applyKeyOrder(
            menu_catalog.map((item) => ({ key: item.key })),
            nav_overrides.order?.length ? nav_overrides.order : defaultOrder,
        ).map((item) => item.key),
        child_order: Object.fromEntries(
            Object.entries(defaultChildOrder).map(([parentKey, keys]) => [
                parentKey,
                applyKeyOrder(
                    keys.map((key) => ({ key })),
                    nav_overrides.child_order?.[parentKey] ?? keys,
                ).map((item) => item.key),
            ]),
        ) as Record<string, string[]>,
    });

    const orderedParents = useMemo(
        () =>
            applyKeyOrder(
                menu_catalog,
                data.order.length ? data.order : defaultOrder,
            ),
        [menu_catalog, data.order, defaultOrder],
    );

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

    function setParentOrder(next: string[]) {
        setData('order', next);
    }

    function setChildOrder(parentKey: string, next: string[]) {
        setData('child_order', {
            ...data.child_order,
            [parentKey]: next,
        });
    }

    function moveParent(key: string, direction: -1 | 1) {
        const index = data.order.indexOf(key);
        if (index < 0) {
            return;
        }
        setParentOrder(moveKey(data.order, index, index + direction));
    }

    function moveChild(parentKey: string, key: string, direction: -1 | 1) {
        const keys = data.child_order[parentKey] ?? [];
        const index = keys.indexOf(key);
        if (index < 0) {
            return;
        }
        setChildOrder(parentKey, moveKey(keys, index, index + direction));
    }

    function onParentDrop(targetKey: string) {
        if (!dragParentKey || dragParentKey === targetKey) {
            setDragParentKey(null);
            return;
        }
        const from = data.order.indexOf(dragParentKey);
        const to = data.order.indexOf(targetKey);
        setParentOrder(moveKey(data.order, from, to));
        setDragParentKey(null);
    }

    function onChildDrop(parentKey: string, targetKey: string) {
        if (!dragChild || dragChild.parentKey !== parentKey || dragChild.key === targetKey) {
            setDragChild(null);
            return;
        }
        const keys = data.child_order[parentKey] ?? [];
        const from = keys.indexOf(dragChild.key);
        const to = keys.indexOf(targetKey);
        setChildOrder(parentKey, moveKey(keys, from, to));
        setDragChild(null);
    }

    function resetOrder() {
        setData({
            ...data,
            order: defaultOrder,
            child_order: defaultChildOrder,
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/admin/menu');
    }

    const grouped = orderedParents.reduce<Record<string, MenuCatalogItem[]>>((acc, item) => {
        (acc[item.group ?? 'Other'] ??= []).push(item);
        return acc;
    }, {});

    return (
        <AppShell title="Menu Configuration">
            <Head title="Menu Configuration" />
            <div className="space-y-6">
                <PageHeader
                    title="Menu Items"
                    description="Reorder menu and submenu items, and control which links appear per role. Hiding a link does not revoke access — server permissions still enforce actions."
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel
                        title="Menu & submenu order"
                        description="Drag items or use the arrows to change sidebar positions. Order applies to all roles."
                        actions={
                            <Button type="button" variant="outline" size="sm" onClick={resetOrder}>
                                Reset to default
                            </Button>
                        }
                    >
                        <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                            {orderedParents.map((item, index) => {
                                const childKeys = data.child_order[item.key] ?? [];
                                const children = applyKeyOrder(
                                    item.children ?? [],
                                    childKeys,
                                );

                                return (
                                    <li key={item.key} className="bg-white">
                                        <div
                                            className={cn(
                                                'flex items-center gap-2 px-3 py-3',
                                                dragParentKey === item.key && 'bg-slate-50',
                                            )}
                                            draggable
                                            onDragStart={() => setDragParentKey(item.key)}
                                            onDragOver={(e: DragEvent) => e.preventDefault()}
                                            onDrop={() => onParentDrop(item.key)}
                                            onDragEnd={() => setDragParentKey(null)}
                                        >
                                            <GripVertical className="h-4 w-4 shrink-0 cursor-grab text-slate-400" />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium text-slate-900">
                                                    {item.label}
                                                </p>
                                                <p className="truncate text-xs text-slate-500">
                                                    {item.href}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={index === 0}
                                                    onClick={() => moveParent(item.key, -1)}
                                                    aria-label={`Move ${item.label} up`}
                                                >
                                                    <ChevronUp className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={index === orderedParents.length - 1}
                                                    onClick={() => moveParent(item.key, 1)}
                                                    aria-label={`Move ${item.label} down`}
                                                >
                                                    <ChevronDown className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </div>

                                        {children.length > 0 && (
                                            <ul className="border-t border-slate-100 bg-slate-50/70">
                                                {children.map((child, childIndex) => (
                                                    <li
                                                        key={child.key}
                                                        className={cn(
                                                            'flex items-center gap-2 py-2.5 pr-3 pl-10',
                                                            dragChild?.key === child.key &&
                                                                'bg-slate-100',
                                                        )}
                                                        draggable
                                                        onDragStart={() =>
                                                            setDragChild({
                                                                parentKey: item.key,
                                                                key: child.key,
                                                            })
                                                        }
                                                        onDragOver={(e: DragEvent) =>
                                                            e.preventDefault()
                                                        }
                                                        onDrop={() =>
                                                            onChildDrop(item.key, child.key)
                                                        }
                                                        onDragEnd={() => setDragChild(null)}
                                                    >
                                                        <GripVertical className="h-4 w-4 shrink-0 cursor-grab text-slate-400" />
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-sm text-slate-800">
                                                                {child.label}
                                                            </p>
                                                            <p className="truncate text-xs text-slate-500">
                                                                {child.href}
                                                            </p>
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-1">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={childIndex === 0}
                                                                onClick={() =>
                                                                    moveChild(
                                                                        item.key,
                                                                        child.key,
                                                                        -1,
                                                                    )
                                                                }
                                                                aria-label={`Move ${child.label} up`}
                                                            >
                                                                <ChevronUp className="h-4 w-4" />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    childIndex ===
                                                                    children.length - 1
                                                                }
                                                                onClick={() =>
                                                                    moveChild(
                                                                        item.key,
                                                                        child.key,
                                                                        1,
                                                                    )
                                                                }
                                                                aria-label={`Move ${child.label} down`}
                                                            >
                                                                <ChevronDown className="h-4 w-4" />
                                                            </Button>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </DataPanel>

                    <DataPanel title="Per-role visibility">
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <label className="text-sm font-medium text-slate-700">
                                Configure role
                            </label>
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
                                        {items.map((item) => {
                                            const childKeys = data.child_order[item.key] ?? [];
                                            const orderedItem = {
                                                ...item,
                                                children: applyKeyOrder(
                                                    item.children ?? [],
                                                    childKeys,
                                                ),
                                            };

                                            return (
                                                <MenuVisibilityRow
                                                    key={item.key}
                                                    item={orderedItem}
                                                    selectedRole={selectedRole}
                                                    isHiddenForRole={isHiddenForRole}
                                                    toggleRoleItem={toggleRoleItem}
                                                />
                                            );
                                        })}
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
    const hidden =
        isHiddenForRole(toggleHref, selectedRole) || isHiddenForRole(item.href, selectedRole);

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
                        {item.active_path && !nested
                            ? `${item.active_path} → ${item.href}`
                            : item.href}
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
