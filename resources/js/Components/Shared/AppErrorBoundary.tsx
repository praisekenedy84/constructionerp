import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    hasError: boolean;
}

const RELOAD_KEY = 'crf:asset-reload';

/**
 * After a deploy, stale JS often crashes when it receives a new page payload.
 * Recover with a single hard reload so the browser picks up the new Vite build.
 */
export default class AppErrorBoundary extends Component<Props, State> {
    state: State = { hasError: false };

    static getDerivedStateFromError(): State {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('App render failed', error, info);

        try {
            const lastReload = sessionStorage.getItem(RELOAD_KEY);
            const now = Date.now();

            // Avoid an infinite reload loop if the new bundle is also broken.
            if (!lastReload || now - Number(lastReload) > 15_000) {
                sessionStorage.setItem(RELOAD_KEY, String(now));
                window.location.reload();
            }
        } catch {
            // sessionStorage unavailable — fall through to the fallback UI
        }
    }

    private handleReload = (): void => {
        try {
            sessionStorage.removeItem(RELOAD_KEY);
        } catch {
            // ignore
        }
        window.location.href = window.location.href;
    };

    render(): ReactNode {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-slate-50 px-4 text-center text-slate-800">
                <h1 className="text-lg font-semibold">The app needs a refresh</h1>
                <p className="max-w-md text-sm text-slate-600">
                    A newer version was deployed. Reload to continue — your session is kept.
                </p>
                <button
                    type="button"
                    onClick={this.handleReload}
                    className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800"
                >
                    Reload app
                </button>
            </div>
        );
    }
}
