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
    disabled = false,
}: {
    onCancel: () => void;
    processing: boolean;
    submitLabel: string;
    processingLabel?: string;
    disabled?: boolean;
}) {
    return (
        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <Button type="button" variant="outline" onClick={onCancel}>
                Cancel
            </Button>
            <Button type="submit" disabled={processing || disabled}>
                {processing ? processingLabel : submitLabel}
            </Button>
        </div>
    );
}

/**
 * Catch-all for validation messages with no field of their own (nested array keys, or backend
 * business rules), so a rejected submit can never look like nothing happened.
 */
export function FormErrorSummary({
    errors,
    handled = [],
}: {
    errors: Record<string, string | undefined>;
    handled?: string[];
}) {
    const messages = Object.entries(errors)
        .filter(([key, message]) => Boolean(message) && !handled.includes(key))
        .map(([, message]) => message as string);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            role="alert"
            className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
        >
            <ul className="space-y-1">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    );
}

export function DialogFormFields({ children }: { children: ReactNode }) {
    return <div className="space-y-4">{children}</div>;
}
