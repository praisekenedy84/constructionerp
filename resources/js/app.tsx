import '../css/app.css';
import AppErrorBoundary from '@/Components/Shared/AppErrorBoundary';
import { createInertiaApp, router } from '@inertiajs/react';
let companyContext = '';
let accountContext = '';
router.on('navigate', event => { accountContext = String((event.detail.page.props.companyContext as { account_id?: number } | undefined)?.account_id ?? ''); companyContext = String(event.detail.page.props.companyContext && (event.detail.page.props.companyContext as { id: string }).id || ''); });
router.on('before', event => { if (companyContext) event.detail.visit.headers['X-Company-Context'] = companyContext; if (accountContext) event.detail.visit.headers['X-Account-Context'] = accountContext; });
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'CRF-ERP';
const RELOAD_KEY = 'crf:asset-reload:' + (document.querySelector<HTMLMetaElement>('meta[name="app-build"]')?.content ?? 'unknown');

function hardReloadOnce(): void {
    try {
        const lastReload = sessionStorage.getItem(RELOAD_KEY);
        const now = Date.now();

        if (!lastReload) {
            sessionStorage.setItem(RELOAD_KEY, String(now));
            window.location.reload();
        }
    } catch {
        // Without a persistent retry marker, automatic reloads can loop forever.
        // The server-rendered recovery controls remain available instead.
    }
}

// Stale tabs after deploy: missing page modules or failed chunk loads.
// (Inertia's own 409 handling already full-reloads on asset version mismatch.)
window.addEventListener('unhandledrejection', (event) => {
    const message = String(event.reason?.message ?? event.reason ?? '');

    if (
        message.includes('Page not found:') ||
        message.includes('Failed to fetch dynamically imported module') ||
        message.includes('Importing a module script failed') ||
        message.includes('error loading dynamically imported module')
    ) {
        event.preventDefault();
        hardReloadOnce();
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');
        const load = pages[`./Pages/${name}.tsx`];
        if (!load) throw new Error(`Page not found: ${name}`);
        return (await load()).default;
    },
    setup({ el, App, props }) {
        accountContext = String((props.initialPage.props.companyContext as { account_id?: number } | undefined)?.account_id ?? '');
        companyContext = String((props.initialPage.props.companyContext as { id?: string } | undefined)?.id ?? '');
        if (!el) throw new Error('Missing application root');
        createRoot(el).render(
            <AppErrorBoundary>
                <App {...props} />
            </AppErrorBoundary>,
        );
        document.getElementById('app-bootstrap-status')?.remove();
    },
    progress: {
        color: '#1e40af',
    },
});
