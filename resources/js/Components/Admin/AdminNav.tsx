import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

interface AdminNavProps {
    active: 'users' | 'staff' | 'settings' | 'menu' | 'permissions';
}

const links = [
    { key: 'users' as const, label: 'Users', href: '/admin/users' },
    { key: 'staff' as const, label: 'Staff', href: '/admin/staff' },
    { key: 'permissions' as const, label: 'Permissions', href: '/admin/permissions' },
    { key: 'menu' as const, label: 'Menu', href: '/admin/menu' },
    { key: 'settings' as const, label: 'Branding', href: '/settings/ui' },
];

export default function AdminNav({ active }: AdminNavProps) {
    return (
        <nav className="flex flex-wrap gap-2 border-b border-slate-200 pb-4">
            {links.map((link) => (
                <Link
                    key={link.key}
                    href={link.href}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                        active === link.key
                            ? 'bg-blue-50 text-blue-700'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                    )}
                >
                    {link.label}
                </Link>
            ))}
        </nav>
    );
}
