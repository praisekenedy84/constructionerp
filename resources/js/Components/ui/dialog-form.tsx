import { Button } from '@/Components/ui/button';
import { ReactNode } from 'react';

/** Ask before closing a create dialog when the form has unsaved edits. */
export function confirmDiscardIfDirty(isDirty: boolean): boolean {
    if (!isDirty) {
        return true;
    }

    return window.confirm('Discard unsaved changes?');
}

export function DialogFormActions({
    onCancel,
    processing,
    submitLabel,
    processingLabel = 'Saving…',
}: {
    onCancel: () => void;
    processing: boolean;
    submitLabel: string;
    processingLabel?: string;
}) {
    return (
        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <Button type="button" variant="outline" onClick={onCancel}>
                Cancel
            </Button>
            <Button type="submit" disabled={processing}>
                {processing ? processingLabel : submitLabel}
            </Button>
        </div>
    );
}

export function DialogFormFields({ children }: { children: ReactNode }) {
    return <div className="space-y-4">{children}</div>;
}
