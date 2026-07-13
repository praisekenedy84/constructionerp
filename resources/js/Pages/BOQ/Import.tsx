import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps, Project } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ImportError {
    row: number;
    field: string;
    message: string;
}

interface BoqImportProps extends PageProps {
    project: Project;
    errors_report?: ImportError[];
}

export default function BoqImport() {
    const { project, errors_report } = usePage<BoqImportProps>().props;
    const { data, setData, post, processing, errors, progress } = useForm<{
        file: File | null;
        column_map: Record<string, string>;
    }>({
        file: null,
        column_map: {
            section: 'Section',
            description: 'Description',
            unit: 'Unit',
            category: 'Category',
            budgeted_qty: 'Qty',
            unit_rate: 'Rate',
        },
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.id}/boq/import`, { forceFormData: true });
    }

    return (
        <AppShell title="Import BOQ">
            <Head title="Import BOQ" />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title="Import BOQ"
                    description={`Upload CSV or Excel for ${project.name}`}
                />

                <form onSubmit={submit} className="space-y-6">
                    <DataPanel title="File Upload">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="file">BOQ File</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".csv,.xlsx,.xls"
                                    onChange={(e) =>
                                        setData('file', e.target.files?.[0] ?? null)
                                    }
                                    required
                                />
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

                    <DataPanel title="Column Mapping">
                        <div className="grid gap-3 sm:grid-cols-2">
                            {Object.entries(data.column_map).map(([field, column]) => (
                                <div key={field} className="space-y-1">
                                    <Label className="capitalize">{field.replace(/_/g, ' ')}</Label>
                                    <Input
                                        value={column}
                                        onChange={(e) =>
                                            setData('column_map', {
                                                ...data.column_map,
                                                [field]: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </DataPanel>

                    {errors_report && errors_report.length > 0 && (
                        <DataPanel title="Import Errors" description={`${errors_report.length} rows failed`}>
                            <div className="max-h-64 overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs text-slate-500">
                                            <th className="pb-2 font-medium">Row</th>
                                            <th className="pb-2 font-medium">Field</th>
                                            <th className="pb-2 font-medium">Error</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {errors_report.map((err, i) => (
                                            <tr key={i}>
                                                <td className="py-2 text-slate-600">{err.row}</td>
                                                <td className="py-2 text-slate-600">{err.field}</td>
                                                <td className="py-2 text-red-600">{err.message}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </DataPanel>
                    )}

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
