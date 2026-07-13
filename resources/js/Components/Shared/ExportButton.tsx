import { Button } from '@/Components/ui/button';
import { Download } from 'lucide-react';

interface ExportButtonProps {
    slug: string;
    format?: 'csv' | 'xlsx' | 'pdf';
    label?: string;
    filters?: Record<string, string | number>;
}

export default function ExportButton({
    slug,
    format = 'csv',
    label,
    filters = {},
}: ExportButtonProps) {
    const params = new URLSearchParams({ format, ...Object.fromEntries(
        Object.entries(filters).map(([k, v]) => [k, String(v)]),
    ) });
    const href = `/reports/export/${slug}?${params.toString()}`;

    return (
        <a href={href} target="_blank" rel="noopener noreferrer">
            <Button variant="outline" size="sm">
                <Download className="h-4 w-4" />
                {label ?? `Export ${format.toUpperCase()}`}
            </Button>
        </a>
    );
}
