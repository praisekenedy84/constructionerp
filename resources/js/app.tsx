import '../css/app.css';
import AppErrorBoundary from '@/Components/Shared/AppErrorBoundary';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'CRF-ERP';
const RELOAD_KEY = 'crf:asset-reload';

function hardReloadOnce(): void {
    try {
        const lastReload = sessionStorage.getItem(RELOAD_KEY);
        const now = Date.now();

        if (!lastReload || now - Number(lastReload) > 15_000) {
            sessionStorage.setItem(RELOAD_KEY, String(now));
            window.location.reload();
        }
    } catch {
        window.location.reload();
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
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(
            <AppErrorBoundary>
                <App {...props} />
            </AppErrorBoundary>,
        );
    },
    progress: {
        color: '#1e40af',
    },
});
