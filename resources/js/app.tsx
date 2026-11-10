import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Movexa';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const locale = (props.initialPage.props as { locale?: string }).locale ?? 'ar';
        const dir = locale === 'ar' ? 'rtl' : 'ltr';
        document.documentElement.dir = dir;
        document.documentElement.lang = locale;
        // Direction is fixed for the whole app: if a visit lands in another language (e.g. right after
        // signing in to an account set to English from an Arabic login page), reload once so dir/lang match.
        router.on('navigate', (e) => {
            if ((e.detail.page.props as { locale?: string }).locale !== locale) window.location.href = e.detail.page.url;
        });
        createRoot(el).render(
            <DirectionProvider dir={dir}>
                <App {...props} />
            </DirectionProvider>,
        );
    },
    progress: { color: '#e50914' },
});

initializeTheme();

if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
