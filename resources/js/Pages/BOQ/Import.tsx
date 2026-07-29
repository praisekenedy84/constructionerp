import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps, Project } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { FormEvent } from 'react';

interface ImportError {
    row: number | null;
    message: string;
}

interface ImportResult {
    success_count: number;
    error_count: number;
    errors: ImportError[];
}

interface BoqImportProps extends PageProps {
    project: Project;
    import_result?: ImportResult | null;
}

const templateColumns = [
    { field: 'Section', note: 'BOQ section name' },
    { field: 'Description', note: 'Item description (required)' },
    { field: 'Unit', note: 'e.g. m3, kg, ea' },
    { field: 'Category', note: 'materials, labor, equipment, …' },
    { field: 'Qty', note: 'Budgeted quantity' },
    { field: 'Rate', note: 'Unit rate (TZS)' },
];

export default function BoqImport() {
    const { project, import_result } = usePage<BoqImportProps>().props;
    const { data, setData, post, processing, errors, progress, clearErrors } = useForm<{
        file: File | null;
    }>({
        file: null,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!data.file) {
            return;
        }

        clearErrors();
        post(`/projects/${project.id}/boq/import`, {
            forceFormData: true,
            preserveScroll: true,
        });
    }

    return (
        <AppShell title="Import BOQ">
            <Head title="Import BOQ" />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title="Import BOQ"
                    description={`Upload an Excel/CSV file for ${project.name}. Use the same column layout as Export Excel.`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <a href={`/projects/${project.id}/boq/export?template=1`}>
                                <Button type="button" variant="outline">
                                    <Download className="h-4 w-4" />
                                    Download template
                                </Button>
                            </a>
                            <Link href={`/projects/${project.id}/boq/create`}>
                                <Button type="button" variant="outline">
                                    Enter items manually
                                </Button>
                            </Link>
                        </div>
                    }
                />

                {import_result && (
                    <DataPanel
                        title="Last import result"
                        description={`${import_result.success_count} imported · ${import_result.error_count} failed`}
                    >
                        {import_result.errors.length > 0 ? (
                            <div className="max-h-64 overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs text-slate-500">
                                            <th className="pb-2 font-medium">Row</th>
                                            <th className="pb-2 font-medium">Error</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {import_result.errors.map((err, i) => (
                                            <tr key={i}>
                                                <td className="py-2 text-slate-600">
                                                    {err.row ?? '—'}
                                                </td>
                                                <td className="py-2 text-red-600">{err.message}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="text-sm text-slate-600">No row errors reported.</p>
                        )}
                    </DataPanel>
                )}

                <DataPanel
                    title="Expected columns"
                    description="Exported BOQ files and the downloadable template use these headers."
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-xs text-slate-500">
                                    <th className="pb-2 pr-4 font-medium">Column</th>
                                    <th className="pb-2 font-medium">Meaning</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {templateColumns.map((col) => (
                                    <tr key={col.field}>
                                        <td className="py-2 pr-4 font-mono text-slate-900">
                                            {col.field}
                                        </td>
                                        <td className="py-2 text-slate-600">{col.note}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </DataPanel>

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="File Upload">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="file">BOQ File</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    onChange={(e) =>
                                        setData('file', e.target.files?.[0] ?? null)
                                    }
                                    required
                                />
                                {data.file && (
                                    <p className="text-xs text-slate-500">Selected: {data.file.name}</p>
                                )}
                                {errors.file && (
                                    <p className="text-sm text-red-600">{errors.file}</p>
                                )}
                            </div>
                            {progress && (
                                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className="h-full bg-blue-600 transition-all"
                                        style={{ width: `${progress.percentage}%` }}
                                    />
                                </div>
                            )}
                        </div>
                    </DataPanel>

                    <div className="flex justify-end gap-3">
                        <Link href={`/projects/${project.id}/boq`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || !data.file}>
                            {processing ? 'Importing…' : 'Import'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
