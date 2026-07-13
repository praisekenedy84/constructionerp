import { Button, ButtonProps } from '@/Components/ui/button';
import { Link } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import type { ComponentProps } from 'react';

type InertiaLinkProps = ComponentProps<typeof Link>;

interface LinkButtonProps extends ButtonProps {
    href: string;
    method?: InertiaLinkProps['method'];
    preserveScroll?: boolean;
}

export function LinkButton({
    href,
    method,
    preserveScroll,
    children,
    variant = 'outline',
    size = 'sm',
    ...buttonProps
}: LinkButtonProps) {
    return (
        <Button variant={variant} size={size} asChild {...buttonProps}>
            <Link href={href} method={method} preserveScroll={preserveScroll}>
                {children}
            </Link>
        </Button>
    );
}

interface IconLinkProps {
    href: string;
    icon: LucideIcon;
    label: string;
    variant?: ButtonProps['variant'];
    size?: ButtonProps['size'];
    method?: InertiaLinkProps['method'];
    external?: boolean;
}

export function IconLink({
    href,
    icon: Icon,
    label,
    variant = 'ghost',
    size = 'sm',
    method,
    external = false,
}: IconLinkProps) {
    if (external) {
        return (
            <Button variant={variant} size={size} asChild>
                <a href={href} target="_blank" rel="noopener noreferrer" title={label} aria-label={label}>
                    <Icon className="h-4 w-4" />
                </a>
            </Button>
        );
    }

    return (
        <Button variant={variant} size={size} asChild>
            <Link href={href} method={method} title={label} aria-label={label}>
                <Icon className="h-4 w-4" />
            </Link>
        </Button>
    );
}
