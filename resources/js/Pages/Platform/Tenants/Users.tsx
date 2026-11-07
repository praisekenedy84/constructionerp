import PlatformShell from '@/Components/Layout/PlatformShell';
import TenantUsersPanel, { TenantUsersPanelProps } from '@/Components/Admin/TenantUsersPanel';
import { Head, Link } from '@inertiajs/react';

export default function PlatformTenantUsers(props: TenantUsersPanelProps) {
    return <PlatformShell title={`${props.tenant.name} ? Users`}>
        <Head title={`${props.tenant.name} Users`} />
        <Link href={`/platform/tenants/${props.tenant.id}`} className="mb-4 block underline">Back to tenant</Link>
        <TenantUsersPanel {...props} />
    </PlatformShell>;
}
